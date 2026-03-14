<?php
// /public/dashboard.php

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/config.php';   // brings in $pdo

// ---------------------------------------------------------------------
// Fetch up‑to‑date user details
// ---------------------------------------------------------------------
$user   = $_SESSION['user'];
$userId = isset($user['user_id']) ? (int)$user['user_id'] : 0;

try {
    $stmt = $pdo->prepare("
        SELECT group_id, full_name
        FROM users
        WHERE user_id = :uid
    ");
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        session_destroy();
        header("Location: login.php");
        exit;
    }

    $user['group_id']  = (int)$row['group_id'];
    $user['full_name'] = $row['full_name'] ?: $user['username'];

    $_SESSION['user']['group_id']  = $user['group_id'];
    $_SESSION['user']['full_name'] = $user['full_name'];
} catch (Exception $e) {
    $user['group_id']  = 0;
    $user['full_name'] = $user['username'];
}

// ---------------------------------------------------------------------
// Group helpers
// ---------------------------------------------------------------------
$is_admin        = ($user['group_id'] === 1);
$is_aesthetician = ($user['group_id'] === 2);
$is_dentist      = ($user['group_id'] === 3);
$is_mo           = ($user['group_id'] === 4);
$is_operations   = ($user['group_id'] === 5);
$is_nurse        = ($user['group_id'] === 6);
$is_pharmacist   = ($user['group_id'] === 7);
$is_group8       = ($user['group_id'] === 8);   // Front‑of‑house / reception
$is_housekeeping = ($user['group_id'] === 9);
$is_assistant    = ($user['group_id'] === 10);
$is_consultant   = ($user['group_id'] === 11);
$is_labrad       = ($user['group_id'] === 12);
$is_physio       = ($user['group_id'] === 20);
$is_stakeholder  = ($user['group_id'] === 21);
$is_psychologist = ($user['group_id'] === 22);
$is_gm           = ($user['group_id'] === 23); // General Manager

$userFullName = $user['full_name'] ?: $user['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>hospital0 - Dashboard – <?php echo htmlspecialchars($user['username']); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/media/sitelogo.png" type="image/png">
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    /* --- NEW STYLES START HERE --- */

    /* --- !! IMPORTANT FIX !! --- */
    /* This overrides the gradient from style.css ONLY for this page,
       allowing the starfield and DNA helix to be visible. */
    body {
        background-image: none !important;
        background-color: #121212 !important;
    }
    
    html, body {
        max-width: 100%;
        overflow-x: hidden;
    }

    /* --- 3D Canvas Background --- */
    #dna-canvas {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: -2; /* Behind everything */
        opacity: 0.3; /* Subtle effect */
    }

    /* --- Animated Starfield Background --- */
    @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }

    .stars, .twinkling {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      width: 100%;
      height: 100%;
      display: block;
      z-index: -3; /* Farthest back */
    }

    .stars {
      background: #000 url(/media/stars.png) repeat top center;
    }

    .twinkling {
      background: transparent url(/media/twinkling.png) repeat top center;
      animation: move-twink-back 200s linear infinite;
    }
    
    /* --- Glassmorphism Effect for Cards --- */
    .group-card {
      display: block;
      text-align: center;
      margin: 20px auto;
      color: #fff;
      padding: 20px;
      border-radius: 15px;
      
      /* The Glass Effect */
      background: rgba(255, 255, 255, 0.1); /* Semi-transparent white */
      backdrop-filter: blur(10px); /* The magic blur effect */
      -webkit-backdrop-filter: blur(10px); /* For Safari */
      border: 1px solid rgba(255, 255, 255, 0.2); /* Subtle border */
      box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
      
      transition: background 0.3s ease, transform 0.3s ease;
    }

    .group-card:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: translateY(-5px); /* Lift effect on hover */
    }

    .group-card img {
      max-height: 80px;
      display: block;
      margin: 0 auto 10px;
    }

    .group-card h5 {
      margin: 5px 0;
      font-weight: 500; /* Slightly bolder for better readability */
    }

    hr.white-line {
      width: 50%;
      background: #fff;
      height: 2px;
      border: none;
      margin: 40px auto;
      opacity: 0.5;
    }
    /* --- END OF NEW STYLES --- */
  </style>
</head>
<body>

<div class="stars"></div>
<div class="twinkling"></div>

<canvas id="dna-canvas"></canvas>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<div class="container">

<?php /*--------------------------------------------------------------
          ADMIN (most privileges)
-------------------------------------------------------------------*/
if ($is_admin): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, Chief Medical Officer
  </h1>
  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Take a look at employees&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="adduser.php"      class="group-card">
        <img src="/media/adduser.png" alt="Add User"><h5>Add&nbsp;User</h5></a></div>
    <div class="col s12 m6 l4"><a href="edituser.php"     class="group-card">
        <img src="/media/edituser.png" alt="Edit User"><h5>Edit&nbsp;User</h5></a></div>
    <div class="col s12 m6 l4"><a href="suspenduser.php"  class="group-card">
        <img src="/media/suspenduser.png" alt="Suspend User"><h5>Suspend&nbsp;User</h5></a></div>
    <div class="col s12 m6 l4"><a href="terminateuser.php" class="group-card">
        <img src="/media/terminateuser.png" alt="Terminate User"><h5>Terminate&nbsp;User</h5></a></div>
    <div class="col s12 m6 l4"><a href="viewuserfile.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="View User File"><h5>View&nbsp;User&nbsp;File</h5></a></div>
<div class="col s12 m6 l4"><a href="wages.php" class="group-card">
        <img src="/media/wagesmanagement.png" alt="Wages and Salaries"><h5>Wages and Salaries</h5></a></div>

    <div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="View All Users"><h5>View&nbsp;All&nbsp;Users</h5></a></div>
  </div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Providing care&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="prescriptions.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Prescriptions"><h5>Prescriptions</h5></a></div>
    <div class="col s12 m6 l4"><a href="files.php" class="group-card">
        <img src="/media/files.png" alt="Patient Files"><h5>Patient&nbsp;Files</h5></a></div>
<div class="col s12 m6 l4"><a href="certificates.php" class="group-card">
        <img src="/media/viewlabledger.png" alt="Certificates"><h5>Certificates</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients&nbsp;Register</h5></a></div>

  </div>

  <hr class="white-line">


  <h2 class="center-align" style="margin-bottom:30px;">Reception Work&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="ticket.php" class="group-card">
        <img src="/media/opd.png" alt="Issue a Ticket"><h5>Issue a Ticket</h5></a></div>
    <div class="col s12 m6 l4"><a href="files.php" class="group-card">
        <img src="/media/newfile.png" alt="Open New File"><h5>Open New File</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients&nbsp;Register</h5></a></div>
<div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
<div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="View All Users"><h5>View&nbsp;All&nbsp;Users</h5></a></div>
  </div>



<hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Take a look at finances&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addrevenue.php" class="group-card">
        <img src="/media/addrevenue.png" alt="Add Services Rendered"><h5>Add Services Rendered</h5></a></div>
    <div class="col s12 m6 l4"><a href="addexpense.php" class="group-card">
        <img src="/media/addexpense.png" alt="Add Expense"><h5>Add&nbsp;Expense</h5></a></div>
    <div class="col s12 m6 l4"><a href="viewledger.php" class="group-card">
        <img src="/media/viewledger.png" alt="View Ledger"><h5>View&nbsp;Ledger</h5></a></div>
    <div class="col s12 m6 l4"><a href="ledgerext.php" class="group-card">
        <img src="/media/pharmacyledger.png" alt="View Pharmacy Ledger"><h5>View&nbsp;Pharmacy Ledger</h5></a></div>
<div class="col s12 m6 l4"><a href="labledger.php" class="group-card">
        <img src="/media/viewlabledger.png" alt="View Lab Ledger"><h5>View&nbsp;Lab Ledger</h5></a></div>
<div class="col s12 m6 l4"><a href="wages.php" class="group-card">
        <img src="/media/wagesmanagement.png" alt="Wages and Salaries"><h5>Wages and Salaries</h5></a></div>

  </div>

  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Clinical Laboratory&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="create_lab_order.php" class="group-card">
        <img src="/media/laborder.png" alt="Create Lab Order"><h5>Create Lab Order</h5></a></div>
    <div class="col s12 m6 l4"><a href="labs.php" class="group-card">
        <img src="/media/managelabs.png" alt="Manage Labs"><h5>Manage Labs</h5></a></div>
    <div class="col s12 m6 l4"><a href="lab_reporting.php" class="group-card">
        <img src="/media/issuelabreport.png" alt="Issue Lab Report"><h5>Issue Lab Report</h5></a></div>
    <div class="col s12 m6 l4"><a href="labledger.php" class="group-card">
        <img src="/media/viewlabledger.png" alt="Lab Ledger"><h5>Lab Ledger</h5></a></div>

  </div>


  <hr class="white-line">



<h2 class="center-align" style="margin-bottom:30px;">Pharmacy&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="pharmacist.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Pharmacy Management System"><h5>Pharmacy Management System</h5></a></div>
    <div class="col s12 m6 l4"><a href="ledgerext.php" class="group-card">
        <img src="/media/pharmacyledger.png" alt="Pharmacy Ledger"><h5>Pharmacy Ledger</h5></a></div>
</div>
    

 <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">
    Communications&hellip;
    <img src="/media/communications.png" alt="Comms" style="height:40px;vertical-align:middle;margin-left:10px;">
  </h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="office_notices.php" class="group-card">
        <img src="/media/issuenotification.png" alt="Notice"><h5>Issue&nbsp;Office&nbsp;Notice</h5></a></div>
<div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>
  </div>

  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Bar&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addbar.php" class="group-card">
        <img src="/media/bar.png" alt="Bar"><h5>Bar</h5></a></div>
</div>


  <hr class="white-line">




<?php /*--------------------------------------------------------------
          GENERAL MANAGER (group 23)
-------------------------------------------------------------------*/
elseif ($is_gm): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, General Manager
  </h1>
  <hr class="white-line">



  <h2 class="center-align" style="margin-bottom:30px;">Front Desk Command</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="ticket.php" class="group-card">
        <img src="/media/opd.png" alt="Issue a Ticket"><h5>Issue a Ticket</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients Register</h5></a></div>
<div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
  </div>


  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Clinical Lab...</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="create_lab_order.php" class="group-card">
        <img src="/media/laborder.png" alt="Create Lab Order"><h5>Create Lab Order</h5></a></div>
</div>



  <h2 class="center-align" style="margin-bottom:30px;">Employee Management&hellip;</h2>
  <div class="row">
    
    <div class="col s12 m6 l4"><a href="edituser.php"     class="group-card">
        <img src="/media/edituser.png" alt="Edit User"><h5>Edit&nbsp;User</h5></a></div>
    <div class="col s12 m6 l4"><a href="suspenduser.php"  class="group-card">
        <img src="/media/suspenduser.png" alt="Suspend User"><h5>Suspend&nbsp;User</h5></a></div>
    <div class="col s12 m6 l4"><a href="terminateuser.php" class="group-card">
        <img src="/media/terminateuser.png" alt="Terminate User"><h5>Terminate&nbsp;User</h5></a></div>
    <div class="col s12 m6 l4"><a href="viewuserfile.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="View User File"><h5>View&nbsp;User&nbsp;File</h5></a></div>
<div class="col s12 m6 l4"><a href="wages.php" class="group-card">
        <img src="/media/wagesmanagement.png" alt="Wages and Salaries"><h5>Wages and Salaries</h5></a></div>

    <div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="View All Users"><h5>View&nbsp;All&nbsp;Users</h5></a></div>
  </div>

  </div>

  <hr class="white-line">

   <h2 class="center-align" style="margin-bottom:30px;">Patient Care & Records&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="prescriptions.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Prescriptions"><h5>Prescriptions</h5></a></div>
    <div class="col s12 m6 l4"><a href="clinicalpsychology.php" class="group-card">
        <img src="/media/files.png" alt="Clinical Psychology"><h5>Clinical Psychology</h5></a></div>
    <div class="col s12 m6 l4"><a href="certificates.php" class="group-card">
        <img src="/media/viewlabledger.png" alt="Certificates"><h5>Certificates</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients&nbsp;Register</h5></a></div>
    <div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
  </div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Financials&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addrevenue.php" class="group-card">
        <img src="/media/addrevenue.png" alt="Add Services Rendered"><h5>Add Services Rendered</h5></a></div>
    <div class="col s12 m6 l4"><a href="addexpense.php" class="group-card">
        <img src="/media/addexpense.png" alt="Add Expense"><h5>Add&nbsp;Expense</h5></a></div>
  </div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Pharmacy&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="pharmacist.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Pharmacy Management System"><h5>Pharmacy Management System</h5></a></div>
  </div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Communications & Admin&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="office_notices.php" class="group-card">
        <img src="/media/issuenotification.png" alt="Notice"><h5>Issue&nbsp;Office&nbsp;Notice</h5></a></div>
    <div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>
    <div class="col s12 m6 l4"><a href="addbar.php" class="group-card">
        <img src="/media/bar.png" alt="Bar"><h5>Bar</h5></a></div>
  </div>

  <hr class="white-line">


<?php /*--------------------------------------------------------------
          Operations (group 5)
-------------------------------------------------------------------*/

elseif ($is_operations): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, Operations Manager...
  </h1>
  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Take a look at employees&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="suspenduser.php"  class="group-card">
        <img src="/media/suspenduser.png" alt="Suspend User"><h5>Suspend&nbsp;User</h5></a></div>
    <div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="View All Users"><h5>View&nbsp;All&nbsp;Users</h5></a></div>
  </div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Providing care&hellip;</h2>
  <div class="row">
    
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients&nbsp;Register</h5></a></div>
<div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>

  </div>

  <hr class="white-line">


  <h2 class="center-align" style="margin-bottom:30px;">Front Desk...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="ticket.php" class="group-card">
        <img src="/media/opd.png" alt="Issue a Ticket"><h5>Issue a Ticket</h5></a></div>
<div class="col s12 m6 l4"><a href="prescriptions.php?action=show" class="group-card">
        <img src="/media/pharmacist.png" alt="Prescriptions"><h5>Print a Prescription</h5></a></div>
<div class="col s12 m6 l4"><a href="https://portal.hospital0/clinicalpsychology.php?action=show" class="group-card">
        <img src="/media/files.png" alt="Psychology"><h5>Clinical Psychology Session</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients Register</h5></a></div>
<div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
  </div>



<hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Take a look at finances&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addrevenue.php" class="group-card">
        <img src="/media/addrevenue.png" alt="Add Services Rendered"><h5>Add Services Rendered / Add Revenue</h5></a></div>
    <div class="col s12 m6 l4"><a href="addexpense.php" class="group-card">
        <img src="/media/addexpense.png" alt="Add Expense"><h5>Add&nbsp;Expense</h5></a></div>
    

  </div>

  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Clinical Laboratory&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="create_lab_order.php" class="group-card">
        <img src="/media/laborder.png" alt="Create Lab Order"><h5>Create Lab Order</h5></a></div>
 

  </div>


  <hr class="white-line">


 <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Staying connected&hellip;</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="office_notices.php" class="group-card">
        <img src="/media/issuenotification.png" alt="Notice"><h5>Office&nbsp;Notices</h5></a></div>
</div>


<?php /*--------------------------------------------------------------
          RECEPTION (group 8)
-------------------------------------------------------------------*/
elseif ($is_group8): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, <?php echo htmlspecialchars($userFullName); ?>
  </h1>
  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Front Desk...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="ticket.php" class="group-card">
        <img src="/media/opd.png" alt="Issue a Ticket"><h5>Issue a Ticket</h5></a></div>
<div class="col s12 m6 l4"><a href="prescriptions.php?action=show" class="group-card">
        <img src="/media/pharmacist.png" alt="Prescriptions"><h5>Print a Prescription</h5></a></div>
<div class="col s12 m6 l4"><a href="https://portal.hospital0/clinicalpsychology.php?action=show" class="group-card">
        <img src="/media/files.png" alt="Psychology"><h5>Clinical Psychology Session</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients Register</h5></a></div>
<div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
  </div>


  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Clinical Lab...</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="create_lab_order.php" class="group-card">
        <img src="/media/laborder.png" alt="Create Lab Order"><h5>Create Lab Order</h5></a></div>
</div>


  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Finances...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addrevenue.php" class="group-card">
        <img src="/media/addrevenue.png" alt="Payment"><h5>Payment Against Services</h5></a></div>
    <div class="col s12 m6 l4"><a href="addexpense.php" class="group-card">
        <img src="/media/addexpense.png" alt="Expense"><h5>Add&nbsp;Expense</h5></a></div>
  </div>

  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Bar&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addbar.php" class="group-card">
        <img src="/media/bar.png" alt="Bar"><h5>Bar</h5></a></div>
</div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Staying connected&hellip;</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="office_notices.php" class="group-card">
        <img src="/media/issuenotification.png" alt="Notice"><h5>Office&nbsp;Notices</h5></a></div>
<div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>
    <div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="Colleagues"><h5>View&nbsp;All&nbsp;Colleagues</h5></a></div>
  </div>


<?php /*--------------------------------------------------------------
          OTA (group 10)
-------------------------------------------------------------------*/
elseif ($is_assistant): ?>   

  <h1 class="center-align" style="margin-top:30px;">
    Welcome, <?php echo htmlspecialchars($userFullName); ?>
  </h1>
  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Front Desk...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="ticket.php" class="group-card">
        <img src="/media/opd.png" alt="Issue a Ticket"><h5>Issue a Ticket</h5></a></div>
<div class="col s12 m6 l4"><a href="prescriptions.php?action=show" class="group-card">
        <img src="/media/pharmacist.png" alt="Prescriptions"><h5>Print a Prescription</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients Register</h5></a></div>
<div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
  </div>


  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Clinical Lab...</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="create_lab_order.php" class="group-card">
        <img src="/media/laborder.png" alt="Create Lab Order"><h5>Create Lab Order</h5></a></div>
</div>


  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Finances...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addrevenue.php" class="group-card">
        <img src="/media/addrevenue.png" alt="Payment"><h5>Payment Against Services</h5></a></div>
    <div class="col s12 m6 l4"><a href="addexpense.php" class="group-card">
        <img src="/media/addexpense.png" alt="Expense"><h5>Add&nbsp;Expense</h5></a></div>
  </div>

  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Bar&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addbar.php" class="group-card">
        <img src="/media/bar.png" alt="Bar"><h5>Bar</h5></a></div>
</div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Staying connected&hellip;</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="office_notices.php" class="group-card">
        <img src="/media/issuenotification.png" alt="Notice"><h5>Office&nbsp;Notices</h5></a></div>
<div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>
    <div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="Colleagues"><h5>View&nbsp;All&nbsp;Colleagues</h5></a></div>
  </div>

<hr class="white-line">

<?php /*--------------------------------------------------------------
          NURSE (group 6)
-------------------------------------------------------------------*/
elseif ($is_nurse): ?>   

  <h1 class="center-align" style="margin-top:30px;">
    Welcome, <?php echo htmlspecialchars($userFullName); ?>
  </h1>
  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Front Desk...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="ticket.php" class="group-card">
        <img src="/media/opd.png" alt="Issue a Ticket"><h5>Issue a Ticket</h5></a></div>
<div class="col s12 m6 l4"><a href="prescriptions.php?action=show" class="group-card">
        <img src="/media/pharmacist.png" alt="Prescriptions"><h5>Print a Prescription</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients Register</h5></a></div>
<div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
  </div>


  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Clinical Lab...</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="create_lab_order.php" class="group-card">
        <img src="/media/laborder.png" alt="Create Lab Order"><h5>Create Lab Order</h5></a></div>
</div>


  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Finances...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addrevenue.php" class="group-card">
        <img src="/media/addrevenue.png" alt="Payment"><h5>Payment Against Services</h5></a></div>
    <div class="col s12 m6 l4"><a href="addexpense.php" class="group-card">
        <img src="/media/addexpense.png" alt="Expense"><h5>Add&nbsp;Expense</h5></a></div>
  </div>

  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Bar&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addbar.php" class="group-card">
        <img src="/media/bar.png" alt="Bar"><h5>Bar</h5></a></div>
</div>

  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Pharmacy&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="pharmacist.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Pharmacy Management System"><h5>Pharmacy Management System</h5></a></div>
    </div>
<hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Staying connected&hellip;</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="office_notices.php" class="group-card">
        <img src="/media/issuenotification.png" alt="Notice"><h5>Office&nbsp;Notices</h5></a></div>
<div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>
    <div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="Colleagues"><h5>View&nbsp;All&nbsp;Colleagues</h5></a></div>
  </div>


  <hr class="white-line">


<?php /*--------------------------------------------------------------
          AESTHETICIAN (group 2)
-------------------------------------------------------------------*/
elseif ($is_aesthetician): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, <?php echo htmlspecialchars($userFullName); ?>
  </h1>
  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Providing care...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="prescriptions.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Prescriptions"><h5>Prescriptions & Advice</h5></a></div>
    <div class="col s12 m6 l4"><a href="aesthetics.php" class="group-card">
        <img src="/media/files.png" alt="Patient Files"><h5>Patient Files</h5></a></div>
<div class="col s12 m6 l4"><a href="certificates.php" class="group-card">
        <img src="/media/viewlabledger.png" alt="Certificates"><h5>Certificates</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients&nbsp;Register</h5></a></div>

  </div>

  <hr class="white-line">
  <h2 class="center-align" style="margin-bottom:30px;">Take a look at finances&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addrevenue.php" class="group-card">
        <img src="/media/addrevenue.png" alt="Add Services Rendered"><h5>Add Services Rendered</h5></a></div>
    <div class="col s12 m6 l4"><a href="addexpense.php" class="group-card">
        <img src="/media/addexpense.png" alt="Add Expense"><h5>Add&nbsp;Expense</h5></a></div>
</div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Appointments...</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
  </div>



  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Staying connected&hellip;</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="office_notices.php" class="group-card">
        <img src="/media/issuenotification.png" alt="Notice"><h5>Office&nbsp;Notices</h5></a></div>
<div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>
    <div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="Colleagues"><h5>View&nbsp;All&nbsp;Colleagues</h5></a></div>
  </div>


<?php /*--------------------------------------------------------------
          DENTIST (group 3)
-------------------------------------------------------------------*/

elseif ($is_dentist): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, <?php echo htmlspecialchars($userFullName); ?>
  </h1>
  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Providing care...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="prescriptions.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Prescriptions"><h5>Prescriptions & Advice</h5></a></div>
    <div class="col s12 m6 l4"><a href="dental.php" class="group-card">
        <img src="/media/files.png" alt="Patient Files"><h5>Patient Files</h5></a></div>
<div class="col s12 m6 l4"><a href="certificates.php" class="group-card">
        <img src="/media/viewlabledger.png" alt="Certificates"><h5>Certificates</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients&nbsp;Register</h5></a></div>

  </div>

  <hr class="white-line">
  <h2 class="center-align" style="margin-bottom:30px;">Take a look at finances&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addrevenue.php" class="group-card">
        <img src="/media/addrevenue.png" alt="Add Services Rendered"><h5>Add Services Rendered</h5></a></div>
    <div class="col s12 m6 l4"><a href="addexpense.php" class="group-card">
        <img src="/media/addexpense.png" alt="Add Expense"><h5>Add&nbsp;Expense</h5></a></div>
</div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Appointments...</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
  </div>



  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Staying connected&hellip;</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="office_notices.php" class="group-card">
        <img src="/media/issuenotification.png" alt="Notice"><h5>Office&nbsp;Notices</h5></a></div>
<div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>
    <div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="Colleagues"><h5>View&nbsp;All&nbsp;Colleagues</h5></a></div>
  </div>




<?php /*--------------------------------------------------------------
          MEDICAL OFFICERS (group 4)
-------------------------------------------------------------------*/
elseif ($is_mo): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, doctor...
  </h1>
  <hr class="white-line">


  <h2 class="center-align" style="margin-bottom:30px;">Providing care&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="prescriptions.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Prescriptions"><h5>Prescriptions</h5></a></div>
    <div class="col s12 m6 l4"><a href="files.php" class="group-card">
        <img src="/media/files.png" alt="Patient Files"><h5>Patient&nbsp;Files</h5></a></div>
<div class="col s12 m6 l4"><a href="certificates.php" class="group-card">
        <img src="/media/viewlabledger.png" alt="Certificates"><h5>Certificates</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients&nbsp;Register</h5></a></div>

  </div>

  <hr class="white-line">


  <h2 class="center-align" style="margin-bottom:30px;">Reception Work&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="ticket.php" class="group-card">
        <img src="/media/opd.png" alt="Issue a Ticket"><h5>Issue a Ticket</h5></a></div>
    <div class="col s12 m6 l4"><a href="files.php" class="group-card">
        <img src="/media/newfile.png" alt="Open New File"><h5>Open New File</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients&nbsp;Register</h5></a></div>
<div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
<div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="View All Users"><h5>View&nbsp;All&nbsp;Users</h5></a></div>
  </div>



<hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Take a look at finances&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addrevenue.php" class="group-card">
        <img src="/media/addrevenue.png" alt="Add Services Rendered"><h5>Add Services Rendered</h5></a></div>
    <div class="col s12 m6 l4"><a href="addexpense.php" class="group-card">
        <img src="/media/addexpense.png" alt="Add Expense"><h5>Add&nbsp;Expense</h5></a></div>
           

  </div>

  <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Pharmacy&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="pharmacist.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Pharmacy Management System"><h5>Pharmacy Management System</h5></a></div>
    </div>
    

 <hr class="white-line">

<h2 class="center-align" style="margin-bottom:30px;">Clinical Laboratory&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="create_lab_order.php" class="group-card">
        <img src="/media/laborder.png" alt="Create Lab Order"><h5>Create Lab Order</h5></a></div>
    
  </div>


  <hr class="white-line">





  <h2 class="center-align" style="margin-bottom:30px;">
    Communications&hellip;
    <img src="/media/communications.png" alt="Comms" style="height:40px;vertical-align:middle;margin-left:10px;">
  </h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="office_notices.php" class="group-card">
        <img src="/media/issuenotification.png" alt="Notice"><h5>Office Notices</h5></a></div>
<div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>
  </div>

  <hr class="white-line">



<?php /*--------------------------------------------------------------
          STAKEHOLDERS (group 21)
-------------------------------------------------------------------*/
elseif ($is_stakeholder): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, dear stakeholder...
  </h1>
  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Managing Finances...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="viewledger.php" class="group-card">
        <img src="/media/stats.png" alt="viewledger.php"><h5>View Ledger</h5></a></div>


  </div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Have a look at Employees...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/techs.png" alt="View All Employees"><h5>View All Employees</h5></a></div>
    
  </div>

  <hr class="white-line">


<?php /*--------------------------------------------------------------
          PHARMACY (group 7)
-------------------------------------------------------------------*/
elseif ($is_pharmacist): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, <?php echo htmlspecialchars($userFullName); ?>
  </h1>
  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Managing Pharmacy...</h2>
  <div class="row">
    <div class="col s12 m6 offset-m3 l4 offset-l4">
      <a href="pharmacist.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Pharmacy Management System" class="responsive-img">
        <h5>Pharmacy&nbsp;Management&nbsp;System</h5>
      </a>
    </div>
    <div class="col s12 m6 l4"><a href="ledgerext.php" class="group-card">
        <img src="/media/pharmacyledger.png" alt="View Pharmacy Ledger"><h5>View&nbsp;Pharmacy Ledger</h5></a></div>
  </div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Managing Bar...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addbar.php" class="group-card">
        <img src="/media/bar.png" alt="Bar"><h5>Bar</h5></a></div>
  </div>

  <h2 class="center-align" style="margin-bottom:30px;">Managing Standards...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>
  </div>





<?php /*--------------------------------------------------------------
          LAB TECH (group 12)
-------------------------------------------------------------------*/
// Note: Changed from $is_labtech to $is_labrad to match your variable definitions
elseif ($is_labrad): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, <?php echo htmlspecialchars($userFullName); ?>
  </h1>
  <hr class="white-line">

  <div class="row" style="margin-top:40px;">
    <div class="col s12 m6 l4"><a href="labs.php" class="group-card">
        <img src="/media/labtech.png" alt="Lab Management System" class="responsive-img">
        <h5>Lab&nbsp;Management&nbsp;System</h5>
      </a>
    </div>

<div class="col s12 m6 l4"><a href="lab_reporting.php" class="group-card">
        <img src="/media/issuelabreport.png" alt="Issue Report" class="responsive-img">
        <h5>Issue Report</h5>
      </a>
    </div>

  </div>

<?php /*--------------------------------------------------------------
          Clinical psychology
-------------------------------------------------------------------*/

elseif ($is_psychologist): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, <?php echo htmlspecialchars($userFullName); ?>
  </h1>
  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Providing care...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="prescriptions.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Prescriptions"><h5>Prescriptions & Advice</h5></a></div>
    <div class="col s12 m6 l4"><a href="clinicalpsychology.php" class="group-card">
        <img src="/media/files.png" alt="Clinical Psychology"><h5>Clinical Psychology</h5></a></div>
<div class="col s12 m6 l4"><a href="certificates.php" class="group-card">
        <img src="/media/viewlabledger.png" alt="Certificates"><h5>Certificates</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients&nbsp;Register</h5></a></div>

  </div>

  <hr class="white-line">
  <h2 class="center-align" style="margin-bottom:30px;">Take a look at finances&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addrevenue.php" class="group-card">
        <img src="/media/addrevenue.png" alt="Add Services Rendered"><h5>Add Services Rendered</h5></a></div>
   
</div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Appointments...</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
  </div>



  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Staying connected&hellip;</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="office_notices.php" class="group-card">
        <img src="/media/issuenotification.png" alt="Notice"><h5>Office&nbsp;Notices</h5></a></div>
<div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>
    <div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="Colleagues"><h5>View&nbsp;All&nbsp;Colleagues</h5></a></div>
  </div>

<?php /*--------------------------------------------------------------
          Physiotherapy
-------------------------------------------------------------------*/

elseif ($is_physio): ?>
  <h1 class="center-align" style="margin-top:30px;">
    Welcome, <?php echo htmlspecialchars($userFullName); ?>
  </h1>
  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Providing care...</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="prescriptions.php" class="group-card">
        <img src="/media/pharmacist.png" alt="Prescriptions"><h5>Prescriptions & Advice</h5></a></div>
    <div class="col s12 m6 l4"><a href="physiotherapy.php" class="group-card">
        <img src="/media/files.png" alt="Physiotherapy"><h5>Physiotherapy</h5></a></div>
    <div class="col s12 m6 l4"><a href="patientregister.php" class="group-card">
        <img src="/media/viewuserfile.png" alt="Patients Register"><h5>Patients&nbsp;Register</h5></a></div>

  </div>

  <hr class="white-line">
  <h2 class="center-align" style="margin-bottom:30px;">Take a look at finances&hellip;</h2>
  <div class="row">
    <div class="col s12 m6 l4"><a href="addrevenue.php" class="group-card">
        <img src="/media/addrevenue.png" alt="Add Services Rendered"><h5>Add Services Rendered</h5></a></div>
   
</div>

  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Appointments...</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="appointments.php" class="group-card">
        <img src="/media/appointments.png" alt="View Appointments"><h5>View Appointments</h5></a></div>
  </div>



  <hr class="white-line">

  <h2 class="center-align" style="margin-bottom:30px;">Staying connected&hellip;</h2>
  <div class="row">
<div class="col s12 m6 l4"><a href="office_notices.php" class="group-card">
        <img src="/media/issuenotification.png" alt="Notice"><h5>Office&nbsp;Notices</h5></a></div>
<div class="col s12 m6 l4"><a href="event_reporting.php" class="group-card">
        <img src="/media/event.png" alt="Notice"><h5>Report An Event</h5></a></div>
    <div class="col s12 m6 l4"><a href="viewallusers.php" class="group-card">
        <img src="/media/viewallusers.png" alt="Colleagues"><h5>View&nbsp;All&nbsp;Colleagues</h5></a></div>
  </div>




<?php /*--------------------------------------------------------------
          ALL OTHERS
-------------------------------------------------------------------*/
else: ?>
  <h3 class="center-align" style="margin-top:50px;">
    Welcome, <?php echo htmlspecialchars($userFullName); ?>!
  </h3>
  <p class="center-align">You do not have administrative privileges.</p>
<?php endif; ?>

</div>

<hr class="white-line">

<?php include_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>

<script type="importmap">
    {
        "imports": {
            "three": "https://unpkg.com/three@0.164.1/build/three.module.js"
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
    
    // Custom curve for the helix shape
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

    // Material for the backbones
    const backboneMaterial = new THREE.MeshStandardMaterial({
        color: 0x2196f3, // A nice blue color
        metalness: 0.5,
        roughness: 0.2
    });

    // Create the two backbones
    const path1 = new HelixCurve(radius, turns, 0);
    const path2 = new HelixCurve(radius, turns, Math.PI);
    const backbone1 = new THREE.Mesh(new THREE.TubeGeometry(path1, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial);
    const backbone2 = new THREE.Mesh(new THREE.TubeGeometry(path2, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial);
    dnaGroup.add(backbone1, backbone2);

    // Material for the base pairs
    const pairMaterial = new THREE.MeshStandardMaterial({
        color: 0xffeb3b, // A contrasting yellow color
        metalness: 0.2,
        roughness: 0.5
    });

    // Create the connecting "rungs" (base pairs)
    const steps = 50;
    for (let i = 0; i <= steps; i++) {
        const t = i / steps;
        const point1 = path1.getPoint(t);
        const point2 = path2.getPoint(t);
        
        // Create a cylinder for each rung
        const direction = new THREE.Vector3().subVectors(point2, point1);
        const rungGeometry = new THREE.CylinderGeometry(0.3, 0.3, direction.length(), 6);
        const rung = new THREE.Mesh(rungGeometry, pairMaterial);
        
        // Position and orient the rung
        rung.position.copy(point1).add(direction.multiplyScalar(0.5));
        rung.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), direction.normalize());
        
        dnaGroup.add(rung);
    }
    
    scene.add(dnaGroup);
    
    // Lighting
    const ambientLight = new THREE.AmbientLight(0xffffff, 0.5);
    scene.add(ambientLight);
    const pointLight = new THREE.PointLight(0xffffff, 1);
    pointLight.position.set(5, 15, 15);
    scene.add(pointLight);

    // --- Animation Loop ---
    function animate() {
        requestAnimationFrame(animate);
        // Rotate the entire DNA group
        dnaGroup.rotation.y += 0.005;
        dnaGroup.rotation.x += 0.001;
        renderer.render(scene, camera);
    }

    animate();

    // --- Handle window resizing for responsiveness ---
    window.addEventListener('resize', () => {
        camera.aspect = window.innerWidth / window.innerHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(window.innerWidth, window.innerHeight);
    });
</script>

</body>
</html>