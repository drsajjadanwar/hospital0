<?php
// /public/addbar.php - Modernized Bar POS System (Corrected)

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/fpdf.php';
require_once __DIR__ . '/includes/code128.php';

// Authorization check
$allowedGroups = [1, 4, 5, 6, 7, 8, 10, 23];
if (!in_array((int)($_SESSION['user']['group_id'] ?? 0), $allowedGroups, true)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

$pdo = $pdo ?? null;
$uname = $_SESSION['user']['username'] ?? 'unknown';
$ufull = $_SESSION['user']['full_name'] ?? $uname;

$error_message = '';
$success_message = '';
$pdfLink = '';

// --- Fetch active employees for the dropdown ---
$employees = [];
try {
    // CORRECTED: Added backticks around column names to avoid SQL keyword conflicts.
    $stmt = $pdo->query("SELECT `user_id`, `full_name` FROM `users` WHERE `terminated` = 0 AND `suspended` = 0 ORDER BY `full_name` ASC");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = 'Error fetching employee list: ' . $e->getMessage();
}


// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $items = $_POST['items'] ?? [];
    $customerType = $_POST['customer_type'] ?? '';
    
    if (empty($items)) {
        $error_message = 'Please add at least one item to the order.';
    } elseif (empty($customerType)) {
        $error_message = 'Please select a customer type (Employee or Patient).';
    } else {
        $pdo->beginTransaction();
        try {
            $grand_total = 0;
            $rowsForPdf = [];
            
            $priceList = [
                'Cardamom Tea' => 85.47, 'Long Black' => 85.47, 'Espresso' => 42.74,
                'Latte' => 85.47, 'Cappuccino' => 128.21, 'Milk' => 42.74
            ];
            
            foreach ($items as $itemData) {
                $itemName = trim($itemData['name']);
                $quantity = (int)($itemData['qty']);

                if (isset($priceList[$itemName]) && $quantity > 0) {
                    $basePrice = $priceList[$itemName];
                    $itemTotal = $basePrice * $quantity;
                    $grand_total += $itemTotal;
                    $rowsForPdf[] = ['name' => $itemName, 'qty' => $quantity, 'price' => $basePrice, 'total' => $itemTotal];
                }
            }

            if ($grand_total <= 0) {
                throw new Exception('Order total is zero. No valid items were processed.');
            }

            $finalAmountWithGST = round($grand_total * 1.17, 2);
            $invoiceId = 'BAR' . str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            
            // --- Prepare data for barledger ---
            $customerName = null;
            $employeeUserId = null;
            $paymentMethod = 'Cash'; // Default

            if ($customerType === 'Employee') {
                $employeeUserId = (int)($_POST['employee_id'] ?? 0);
                $paymentMethod = $_POST['payment_method'] ?? 'Cash';
                if ($employeeUserId > 0) {
                     $stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
                     $stmt->execute([$employeeUserId]);
                     $customerName = $stmt->fetchColumn();
                } else {
                    throw new Exception('An employee must be selected for an employee transaction.');
                }
            } else { // Patient
                $customerName = !empty($_POST['patient_name']) ? trim($_POST['patient_name']) : 'Walk-in Patient';
                $paymentMethod = 'Cash';
            }

            // 1. Insert into barledger for ALL transactions
            $barStmt = $pdo->prepare(
                "INSERT INTO barledger (datetime, invoice_id, customer_type, customer_name, employee_user_id, payment_method, total_amount, created_by_user) 
                 VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)"
            );
            $barStmt->execute([
                $invoiceId,
                $customerType,
                $customerName,
                $employeeUserId,
                $paymentMethod,
                $finalAmountWithGST,
                $uname
            ]);

            // 2. Insert into generalledger ONLY for CASH transactions
            if ($paymentMethod === 'Cash') {
                $glStmt = $pdo->prepare("INSERT INTO generalledger (datetime, description, amount, user) VALUES (NOW(), ?, ?, ?)");
                $glStmt->execute([$invoiceId, $finalAmountWithGST, $uname]);
            }

            // --- PDF Generation ---
            date_default_timezone_set('Asia/Karachi');
            $pdf = new PDF_Code128('P', 'mm', 'A4');
            $pdf->AddFont('MyriadPro-Regular', '', 'MyriadPro-Regular.php');
            $pdf->SetAutoPageBreak(true, 35);
            $pdf->AddPage();

            if (is_readable(__DIR__ . '/media/headerlogo.jpg')) {
                list($logoWidth, $logoHeight) = getimagesize(__DIR__ . '/media/headerlogo.jpg');
                $aspectRatio = $logoHeight / $logoWidth;
                $imageWidth = $pdf->GetPageWidth() - 20;
                $imageHeight = $imageWidth * $aspectRatio;
                $pdf->Image(__DIR__ . '/media/headerlogo.jpg', 10, 10, $imageWidth, $imageHeight);
                $pdf->SetY(10 + $imageHeight + 10);
            } else {
                 $pdf->SetY(40);
            }
            
            $pdf->SetFont('MyriadPro-Regular', '', 20);
            $pdf->Cell(0, 10, 'Bar Invoice', 0, 1, 'C');
            $pdf->SetFont('MyriadPro-Regular', '', 10);
            $pdf->Cell(0, 6, 'Date: ' . date('d-M-Y h:i A'), 0, 1, 'C');
            $pdf->Ln(5);
            
            $pdf->Code128(($pdf->GetPageWidth() - 80) / 2, $pdf->GetY(), $invoiceId, 80, 15);
            $pdf->Ln(20);

            // Customer and Payment Info
            $pdf->SetFont('MyriadPro-Regular', '', 11);
            $pdf->SetFillColor(245, 245, 245);
            $pdf->Cell(40, 8, 'Customer Type:', 1, 0, 'L', true);
            $pdf->Cell(150, 8, $customerType, 1, 1, 'L');
            $pdf->Cell(40, 8, 'Customer Name:', 1, 0, 'L', true);
            $pdf->Cell(150, 8, $customerName, 1, 1, 'L');
            $pdf->Cell(40, 8, 'Payment Method:', 1, 0, 'L', true);
            $pdf->Cell(150, 8, $paymentMethod, 1, 1, 'L');
            $pdf->Ln(10);


            $pdf->SetFont('MyriadPro-Regular', '', 12);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->SetTextColor(0,0,0);
            $pdf->Cell(90, 10, 'Item', 1, 0, 'L', true);
            $pdf->Cell(20, 10, 'Qty', 1, 0, 'C', true);
            $pdf->Cell(30, 10, 'Price', 1, 0, 'R', true);
            $pdf->Cell(50, 10, 'Total', 1, 1, 'R', true);

            $pdf->SetFont('MyriadPro-Regular', '', 11);
            $pdf->SetTextColor(0,0,0);
            foreach ($rowsForPdf as $row) {
                $pdf->Cell(90, 10, $row['name'], 1);
                $pdf->Cell(20, 10, $row['qty'], 1, 0, 'C');
                $pdf->Cell(30, 10, number_format($row['price'], 2), 1, 0, 'R');
                $pdf->Cell(50, 10, number_format($row['total'], 2), 1, 1, 'R');
            }

            $gst_amount = $grand_total * 0.17;
            
            $pdf->SetFont('MyriadPro-Regular', '', 12);
            $pdf->Cell(140, 10, 'Subtotal', 1, 0, 'R');
            $pdf->Cell(50, 10, number_format($grand_total, 2), 1, 1, 'R');
            $pdf->Cell(140, 10, 'GST (17%)', 1, 0, 'R');
            $pdf->Cell(50, 10, number_format($gst_amount, 2), 1, 1, 'R');
            $pdf->SetFont('MyriadPro-Regular', '', 14);
            $pdf->Cell(140, 12, 'Grand Total (PKR)', 1, 0, 'R');
            $pdf->Cell(50, 12, number_format($finalAmountWithGST, 2), 1, 1, 'R');
            
            $pdf->SetAutoPageBreak(false);
            $pdf->SetY(-30);
            $pdf->SetFont('MyriadPro-Regular', '', 9);
            $pdf->SetTextColor(128, 128, 128);
            $pdf->Cell(0, 6, 'Invoice generated by ' . $ufull, 0, 1, 'C');
            $pdf->Cell(0, 6, 'hospital0', 0, 1, 'C');
            
            $basePath = __DIR__ . '/bardata/';
            if (!is_dir($basePath)) { mkdir($basePath, 0775, true); }
            $finalName = $invoiceId . '.pdf';
            $fullPath = $basePath . $finalName;
            $pdf->Output('F', $fullPath);
            
            $success_message = 'Bar invoice generated successfully!';
            $pdfLink = 'bardata/' . $finalName;

            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error_message = 'Failed to process order: ' . $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>hospital0 - Bar POS - hospital0</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/media/sitelogo.png" type="image/png">
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
    h3.center-align { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
    .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
    
    .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 2rem; margin-top: 1.5rem; }
    
    #menuItems { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; }
    .menu-item { background: rgba(0, 229, 255, 0.1); border: 1px solid rgba(0, 229, 255, 0.3); padding: 20px; text-align: center; border-radius: 10px; cursor: pointer; transition: all 0.3s; user-select: none; margin: 2px; }
    .menu-item:hover { background: rgba(0, 229, 255, 0.2); transform: translateY(-3px); }
    .menu-item h6 { margin: 0; font-weight: 500; font-size: 1.1rem; } .menu-item p { margin: 5px 0 0 0; color: #bdbdbd; font-size: 0.9rem; }
    
    #billTable th, #billTable td { padding: 12px 8px; }
    .quantity-controls { display: flex; align-items: center; justify-content: center; }
    .quantity-controls .btn-small { background-color: rgba(255,255,255,0.1); margin: 0 5px; }
    
    .total-section { font-size: 1.2rem; margin-top: 1rem; } .total-section .total-value { font-size: 1.5rem; font-weight: bold; color: #00e5ff; }

    .message-area { padding: 10px 15px; margin-bottom: 20px; border-radius: 8px; text-align: center; border: 1px solid; }
    .message-area.success { background-color: rgba(76, 175, 80, 0.25); color: #c8e6c9; border-color: rgba(129, 199, 132, 0.5); }
    .message-area.error { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border-color: rgba(239, 154, 154, 0.5); }
    
    /* Input field styles */
    .input-field input, .input-field .select-dropdown, .input-field textarea { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
    .input-field label { color: #bdbdbd !important; } .input-field label.active { color: #00e5ff !important; }
    .input-field input:focus, .input-field .select-wrapper input.select-dropdown:focus, .input-field textarea:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
    ul.dropdown-content { background-color: #2a2a2a; } .dropdown-content li>span { color: #fff !important; }
    [type="radio"]:checked+span:after, [type="radio"].with-gap:checked+span:before, [type="radio"].with-gap:checked+span:after { border: 2px solid #00e5ff; }
    [type="radio"]:checked+span:after, [type="radio"].with-gap:checked+span:after { background-color: #00e5ff; }

  </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
  <h3 class="center-align white-text" style="margin-top: 30px;">Bar Point of Sale</h3>
  <hr class="white-line">

  <?php if (!empty($error_message)): ?>
    <div class="message-area error"><?php echo htmlspecialchars($error_message); ?></div>
     <div class="center-align"><a href="addbar.php" class="btn-large waves-effect waves-light grey">New Order</a></div>
  <?php elseif (!empty($success_message)): ?>
    <div class="glass-card center-align">
        <div class="message-area success"><?php echo htmlspecialchars($success_message); ?></div>
        <a href="<?php echo htmlspecialchars($pdfLink); ?>" target="_blank" class="btn-large waves-effect waves-light" style="background-color: #00bfa5;"><i class="material-icons left">picture_as_pdf</i>Download Invoice</a>
        <a href="addbar.php" class="btn-large waves-effect waves-light grey">New Order</a>
    </div>
  <?php else: ?>

<div class="message-area notice">
      <i class="material-icons left">warning</i>
      <strong>Notice:</strong> The payment can only be collected at either of the designated points: the front desk and the pharmacy. Strict compliance is mandatory.
  </div>

  <form method="POST">
    <div class="row">
        <div class="col s12 m7">
            <div class="glass-card">
                <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Menu</h5>
                <div id="menuItems"></div>
            </div>
        </div>

        <div class="col s12 m5">
            <div class="glass-card">
                <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Customer Details</h5>
                <p>
                    <label><input name="customer_type" type="radio" value="Patient" required /><span>Patient</span></label>
                    <label style="margin-left:20px;"><input name="customer_type" type="radio" value="Employee" required /><span>Employee</span></label>
                </p>

                <div id="patient-fields" style="display:none;">
                    <div class="input-field">
                        <input id="patient_name" name="patient_name" type="text" class="validate">
                        <label for="patient_name">Patient Name (Optional)</label>
                    </div>
                     <p>Payment Method: <strong>Cash Only</strong></p>
                </div>

                <div id="employee-fields" style="display:none;">
                     <div class="input-field">
                        <i class="material-icons prefix">search</i>
                        <input type="text" id="employee-search" onkeyup="filterEmployees()" placeholder="Search for employee...">
                    </div>
                    <div class="input-field">
                        <select id="employee_id" name="employee_id">
                            <option value="" disabled selected>Select an employee</option>
                            <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['user_id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="employee_id">Employee</label>
                    </div>
                    <p>
                        <label><input name="payment_method" type="radio" value="Cash" checked /><span>Cash</span></label>
                        <label style="margin-left:20px;"><input name="payment_method" type="radio" value="Credit" /><span>Credit</span></label>
                    </p>
                </div>
            </div>

            <div class="glass-card">
                <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Current Bill</h5>
                <table class="highlight">
                    <thead><tr><th>Item</th><th class="center-align">Quantity</th><th class="right-align">Total</th><th></th></tr></thead>
                    <tbody id="billTableBody"><tr id="noItemsRow"><td colspan="4" class="center-align grey-text">No items added yet</td></tr></tbody>
                </table>
                <div class="right-align total-section">
                    <p>Subtotal: <span id="subtotalAmount">PKR 0.00</span></p>
                    <p>GST (17%): <span id="gstAmount">PKR 0.00</span></p>
                    <p class="total-value">Total: <span id="totalAmount">PKR 0.00</span></p>
                </div>
                <div class="center-align" style="margin-top: 2rem;">
                    <button type="submit" class="btn-large waves-effect waves-light" style="background-color: #00bfa5;"><i class="material-icons left">receipt_long</i>Generate Invoice</button>
                </div>
            </div>
        </div>
    </div>
  </form>
  <?php endif; ?>
</main>
<br>

<?php include_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script type="module">
    // 3D background animation
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
document.addEventListener('DOMContentLoaded', function () {
    M.AutoInit(); // Initialize Materialize components

    const menu = {
        'Cardamom Tea': { final: 100, base: 85.47 }, 'Long Black': { final: 100, base: 85.47 },
        'Espresso': { final: 50, base: 42.74 }, 'Latte': { final: 100, base: 85.47 },
        'Cappuccino': { final: 150, base: 128.21 }, 'Milk': { final: 50, base: 42.74 }
    };
    const menuContainer = document.getElementById('menuItems');
    for (const name in menu) {
        menuContainer.innerHTML += `<div class="menu-item" data-name="${name}" data-price="${menu[name].base}">
                                        <h6>${name}</h6><p>PKR ${menu[name].final.toFixed(2)}</p>
                                    </div>`;
    }

    const billBody = document.getElementById('billTableBody');
    const noItemsRow = document.getElementById('noItemsRow');
    const bill = {};

    function updateBill() {
        billBody.innerHTML = ''; let subtotal = 0; let index = 0;
        if (Object.keys(bill).length === 0) { billBody.appendChild(noItemsRow); }
        else {
            for (const name in bill) {
                const item = bill[name]; const itemTotal = item.price * item.qty; subtotal += itemTotal;
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${name}<input type="hidden" name="items[${index}][name]" value="${name}"><input type="hidden" name="items[${index}][qty]" value="${item.qty}"></td>
                    <td class="center-align"><div class="quantity-controls"><a class="btn-small waves-effect waves-light btn-decrease" data-name="${name}">-</a><span style="margin:0 10px;">${item.qty}</span><a class="btn-small waves-effect waves-light btn-increase" data-name="${name}">+</a></div></td>
                    <td class="right-align">PKR ${itemTotal.toFixed(2)}</td>
                    <td><a class="btn-small red waves-effect waves-light btn-remove" data-name="${name}"><i class="material-icons">close</i></a></td>`;
                billBody.appendChild(row); index++;
            }
        }
        const gst = subtotal * 0.17; const total = subtotal + gst;
        document.getElementById('subtotalAmount').textContent = `PKR ${subtotal.toFixed(2)}`;
        document.getElementById('gstAmount').textContent = `PKR ${gst.toFixed(2)}`;
        document.getElementById('totalAmount').textContent = `PKR ${total.toFixed(2)}`;
    }

    menuContainer.addEventListener('click', e => {
        const itemEl = e.target.closest('.menu-item'); if (!itemEl) return;
        const name = itemEl.dataset.name; const price = parseFloat(itemEl.dataset.price);
        if (bill[name]) { bill[name].qty++; } else { bill[name] = { price: price, qty: 1 }; }
        updateBill();
    });

    billBody.addEventListener('click', e => {
        const btn = e.target.closest('a'); if (!btn) return;
        const name = btn.dataset.name;
        if (btn.classList.contains('btn-increase')) { if (bill[name]) bill[name].qty++; }
        else if (btn.classList.contains('btn-decrease')) { if (bill[name] && bill[name].qty > 1) { bill[name].qty--; } else { delete bill[name]; } }
        else if (btn.classList.contains('btn-remove')) { delete bill[name]; }
        updateBill();
    });

    // --- New logic for customer type switching ---
    const customerTypeRadios = document.querySelectorAll('input[name="customer_type"]');
    const patientFields = document.getElementById('patient-fields');
    const employeeFields = document.getElementById('employee-fields');
    const employeeSelect = document.getElementById('employee_id');

    customerTypeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'Patient') {
                patientFields.style.display = 'block';
                employeeFields.style.display = 'none';
                employeeSelect.required = false;
            } else if (this.value === 'Employee') {
                patientFields.style.display = 'none';
                employeeFields.style.display = 'block';
                employeeSelect.required = true;
            }
        });
    });
});

// --- Employee search filter ---
function filterEmployees() {
    let input = document.getElementById('employee-search');
    let filter = input.value.toUpperCase();
    let select = document.getElementById('employee_id');
    let options = select.getElementsByTagName('option');
    for (let i = 0; i < options.length; i++) {
        let txtValue = options[i].textContent || options[i].innerText;
        if (i === 0 || txtValue.toUpperCase().indexOf(filter) > -1) { // Always show the placeholder
            options[i].style.display = "";
        } else {
            options[i].style.display = "none";
        }
    }
    // Re-initialize the select to update the dropdown view
    M.FormSelect.init(select);
}
</script>

</body>
</html>