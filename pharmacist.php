<?php
// /public/pharmacist.php - Modernized for high-tech theme & improved UX
session_start();
require_once __DIR__ . '/includes/config.php';
set_include_path(__DIR__ . '/includes' . PATH_SEPARATOR . get_include_path());
require_once 'code128.php';

/* Authorise – groups 1, 7, 23 */
if (!isset($_SESSION['user']) || !in_array((int)$_SESSION['user']['group_id'], [1, 4, 6, 7, 23], true)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}
$currentUsername = $_SESSION['user']['username'] ?? 'unknown';

/* Banner */
$flash = '';
$flashType = 'ok';
$invoiceRel = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        switch ($_POST['action'] ?? '') {
            case 'add_drug':
                $brand = trim($_POST['brand'] ?? ''); $generic = trim($_POST['generic'] ?? ''); $dosage = trim($_POST['dosage'] ?? '');
                $qty = (int)($_POST['qty'] ?? 0); $sp = (float)($_POST['sp'] ?? 0);
                if (empty($brand) || empty($generic) || empty($dosage) || $qty < 0 || $sp < 0) { throw new RuntimeException('All fields are required and numeric values must be non-negative.'); }
                $stmt = $pdo->prepare("SELECT * FROM drug_inventory WHERE brand_name = ? AND generic_name = ? AND dosage = ?");
                $stmt->execute([$brand, $generic, $dosage]);
                if ($existingDrug = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->prepare("UPDATE drug_inventory SET quantity = quantity + ?, selling_price = ? WHERE id = ?")->execute([$qty, $sp, $existingDrug['id']]);
                    $flash = 'Drug already exists. Quantity and price have been updated.';
                } else {
                    $pdo->prepare("INSERT INTO drug_inventory (brand_name, generic_name, dosage, quantity, purchase_price, selling_price) VALUES (?, ?, ?, ?, ?, ?)")->execute([$brand, $generic, $dosage, $qty, 0.00, $sp]);
                    $flash = 'Drug saved successfully.';
                }
                break;
            case 'delete_drugs':
                $ids = $_POST['del'] ?? []; if (!$ids) { throw new RuntimeException('No drugs selected for deletion.'); }
                $in = implode(',', array_map('intval', $ids));
                $pdo->exec("DELETE FROM drug_inventory WHERE id IN ($in)");
                $flash = 'Selected drugs deleted.';
                break;
            case 'update_rack':
                $drug_id = (int)($_POST['drug_id'] ?? 0); $rack_info = trim($_POST['rack_info'] ?? '');
                if ($drug_id <= 0) { throw new RuntimeException('Invalid drug ID.'); }
                if (!preg_match('/^[\d, ]*$/', $rack_info)) { throw new RuntimeException('Invalid rack info. Only numbers and commas are allowed.'); }
                $rack_info = trim(preg_replace('/\s*,\s*/', ',', $rack_info), ' ,');
                $pdo->prepare("UPDATE drug_inventory SET rack = ? WHERE id = ?")->execute([empty($rack_info) ? null : $rack_info, $drug_id]);
                $flash = 'Rack information updated successfully.';
                break;
            case 'dispense':
                $items = $_POST['order'] ?? []; if (!$items) throw new RuntimeException('No drugs selected.');
                $patientName = trim($_POST['patient_name'] ?? '');
                $pdo->beginTransaction();
                $rows = []; $grand = 0; $saleItemsToInsert = [];
                foreach ($items as $it) {
                    $id = (int)$it['id']; $q = (int)$it['q']; if ($id < 1 || $q < 1) continue;
                    $d_stmt = $pdo->prepare("SELECT * FROM drug_inventory WHERE id = ? FOR UPDATE"); $d_stmt->execute([$id]);
                    if (!$drug = $d_stmt->fetch(PDO::FETCH_ASSOC)) continue;
                    if ($drug['quantity'] < $q) { throw new RuntimeException("Insufficient stock for ".htmlspecialchars($drug['brand_name']).". Available: ".$drug['quantity'].", Requested: ".$q); }
                    $pdo->prepare("UPDATE drug_inventory SET quantity = quantity - ? WHERE id = ?")->execute([$q, $id]);
                    $tot = $q * $drug['selling_price'];
                    $rows[] = ['name' => $drug['brand_name'].' ('.$drug['generic_name'].') '.$drug['dosage'], 'qty' => $q, 'price' => $drug['selling_price'], 'total' => $tot];
                    $grand += $tot;
                    $saleItemsToInsert[] = ['drug_id' => $id, 'quantity_sold' => $q, 'price_per_item' => $drug['selling_price']];
                }
                if (!$rows) throw new RuntimeException('Nothing valid to invoice.');
                $serial = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO pharmacyledger (datetime, description, amount, user) VALUES (NOW(), ?, ?, ?)")->execute([$serial, $grand, $currentUsername]);
                $itemsStmt = $pdo->prepare("INSERT INTO pharmacy_sales_items (serial, drug_id, quantity_sold, price_per_item) VALUES (?, ?, ?, ?)");
                foreach ($saleItemsToInsert as $saleItem) { $itemsStmt->execute([$serial, $saleItem['drug_id'], $saleItem['quantity_sold'], $saleItem['price_per_item']]); }
                $pdo->commit();
                /* PDF Generation */
                $dir = __DIR__ . '/pharmacy/'; @mkdir($dir, 0777, true); $base = 'invoice_' . $serial . '.pdf'; $file = $dir . $base; $invoiceRel = 'pharmacy/'.basename($file);
                date_default_timezone_set('Asia/Karachi'); $now = date('d-m-Y H:i');
                class PDF_Invoice extends PDF_Code128 {}
                $pdf = new PDF_Invoice('P', 'mm', 'A4'); $pdf->AddFont('MyriadPro-Regular', '', 'MyriadPro-Regular.php'); $pdf->AddPage();
                if (file_exists(__DIR__ . '/media/logo-print.png')) { $pdf->Image(__DIR__.'/media/logo-print.png', ($pdf->GetPageWidth()-60)/2, 8, 60); }
                $pdf->SetFont('MyriadPro-Regular','',10); $pdf->SetXY($pdf->GetPageWidth()-50,8); $pdf->Cell(50,5,$now,0,0,'R');
                $pdf->Ln(28); $pdf->SetFont('MyriadPro-Regular','',15); $pdf->Cell(0,8,'hospital0',0,1,'C');
                $pdf->SetFont('MyriadPro-Regular','',11); $pdf->Cell(0,6,'Pharmacy Invoice',0,1,'C'); $pdf->Ln(4);
                $pdf->Code128(50,$pdf->GetY(),$serial,110,18); $pdf->Ln(22);
                if(!empty($patientName)){ $pdf->SetFont('MyriadPro-Regular','',12); $pdf->Cell(0,8,'Patient: '.$patientName,0,1,'C'); $pdf->Ln(2); }
                $pdf->SetFont('MyriadPro-Regular','',10); $pdf->Cell(90,7,'Drug',1); $pdf->Cell(20,7,'Qty',1,0,'C'); $pdf->Cell(30,7,'Price',1,0,'R'); $pdf->Cell(30,7,'Total',1,1,'R');
                foreach($rows as $r){ $pdf->Cell(90,7,$r['name'],1); $pdf->Cell(20,7,$r['qty'],1,0,'C'); $pdf->Cell(30,7,number_format($r['price'],2),1,0,'R'); $pdf->Cell(30,7,number_format($r['total'],2),1,1,'R'); }
                $pdf->SetFont('MyriadPro-Regular','',11); $pdf->Cell(140,8,'Grand Total (PKR)',1,0,'R'); $pdf->Cell(30,8,number_format($grand,2),1,1,'R'); $pdf->Ln(6);
                $pdf->Cell(0,5,'Serial #: '.$serial,0,1,'C'); $pdf->Cell(0,5,'This file is electronically signed.',0,1,'C');
                $pdf->Output('F', $file);
                $flash = 'Invoice created successfully.';
                break;
            case 'add_expense':
                $description = trim($_POST['description'] ?? ''); $amount = (float)($_POST['amount'] ?? 0);
                if (empty($description) || $amount <= 0) { throw new RuntimeException('Description cannot be empty and amount must be positive.'); }
                $pdo->prepare("INSERT INTO pharmacy_expenses (description, amount, user) VALUES (?, ?, ?)")->execute([$description, $amount, $currentUsername]);
                $flash = 'Expense added successfully.';
                break;
            case 'return_items':
                $returns = $_POST['returns'] ?? []; if (empty($returns)) { throw new RuntimeException('No items selected for return.'); }
                $pdo->beginTransaction(); $totalRefund = 0;
                foreach ($returns as $sale_item_id => $return_data) {
                    $qty_to_return = (int)($return_data['qty'] ?? 0); if ($qty_to_return <= 0) continue;
                    $stmt = $pdo->prepare("SELECT si.*, di.brand_name FROM pharmacy_sales_items si JOIN drug_inventory di ON si.drug_id = di.id WHERE si.sale_item_id = ? FOR UPDATE");
                    $stmt->execute([$sale_item_id]); $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$item) { throw new RuntimeException("Invalid item ID #$sale_item_id for return."); }
                    $available_to_return = $item['quantity_sold'] - $item['quantity_returned'];
                    if ($qty_to_return > $available_to_return) { throw new RuntimeException("Cannot return {$qty_to_return} of {$item['brand_name']}. Only {$available_to_return} available."); }
                    $pdo->prepare("UPDATE pharmacy_sales_items SET quantity_returned = quantity_returned + ? WHERE sale_item_id = ?")->execute([$qty_to_return, $sale_item_id]);
                    $pdo->prepare("UPDATE drug_inventory SET quantity = quantity + ? WHERE id = ?")->execute([$qty_to_return, $item['drug_id']]);
                    $totalRefund += $qty_to_return * $item['price_per_item'];
                }
                if ($totalRefund > 0) {
                    $return_desc = "Return for invoice " . ($_POST['return_serial'] ?? 'N/A');
                    $pdo->prepare("INSERT INTO pharmacyledger (datetime, description, amount, user) VALUES (NOW(), ?, ?, ?)")->execute([$return_desc, -$totalRefund, $currentUsername]);
                }
                $pdo->commit();
                $flash = 'Items returned successfully. Total refund: PKR ' . number_format($totalRefund, 2);
                break;
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $flash = 'Error: ' . $e->getMessage();
    $flashType = 'err';
}

$drugs = $pdo->query("SELECT * FROM drug_inventory ORDER BY brand_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$recentSales = $pdo->query("SELECT * FROM pharmacyledger WHERE amount > 0 ORDER BY datetime DESC")->fetchAll(PDO::FETCH_ASSOC);
$allExpenses = $pdo->query("SELECT * FROM pharmacy_expenses ORDER BY expense_date DESC")->fetchAll(PDO::FETCH_ASSOC);

date_default_timezone_set('Asia/Karachi');
$monthlyFinancials = [];
for ($i = 0; $i < 12; $i++) {
    $monthValue = date('Y-m', strtotime("-$i months"));
    $monthStart = date('Y-m-01 00:00:00', strtotime($monthValue)); $monthEnd = date('Y-m-t 23:59:59', strtotime($monthValue));
    $stmtSales = $pdo->prepare("SELECT SUM(amount) as total FROM pharmacyledger WHERE amount > 0 AND datetime BETWEEN ? AND ?");
    $stmtSales->execute([$monthStart, $monthEnd]); $sales = $stmtSales->fetchColumn() ?: 0;
    $stmtExpenses = $pdo->prepare("SELECT SUM(amount) as total FROM pharmacy_expenses WHERE expense_date BETWEEN ? AND ?");
    $stmtExpenses->execute([$monthStart, $monthEnd]); $expenses = $stmtExpenses->fetchColumn() ?: 0;
    $monthlyFinancials[$monthValue] = ['sales' => $sales, 'expenses' => $expenses, 'account' => $sales - $expenses];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>hospital0 - Pharmacy – hospital0</title>
    <link rel="icon" href="/media/sitelogo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-image: none !important; background-color: #121212 !important; color: #fff; display: flex; min-height: 100vh; flex-direction: column; }
        main { flex: 1 0 auto; }
        @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
        .stars, .twinkling { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; display: block; z-index: -3; }
        .stars { background: #000 url(/media/stars.png) repeat top center; }
        .twinkling { background: transparent url(/media/twinkling.png) repeat top center; animation: move-twink-back 200s linear infinite; }
        #dna-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; opacity: 0.3; }
        h1.site-title, h5.site-subtitle { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
        .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }

        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 2rem; margin-top: 1rem; display: none; }
        .glass-card.active { display: block; }
        
        .stat-box { background: rgba(255, 255, 255, 0.05); padding: 20px; border-radius: 10px; text-align: center; color: white; margin-bottom: 1rem; border: 1px solid rgba(255, 255, 255, 0.1); }
        .stat-box h5 { margin: 0 0 10px 0; font-size: 1.1rem; color: #bdbdbd; }
        .stat-box p { margin: 0; font-size: 2rem; font-weight: bold; }
        p.green-text { color: #81c784 !important; } p.red-text { color: #e57373 !important; }

        .input-field input, .input-field input::placeholder, .input-field .select-dropdown, .input-field textarea { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
        .input-field label { color: #bdbdbd !important; }
        .input-field label.active { color: #00e5ff !important; }
        .input-field input:focus, .input-field textarea:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
        ul.dropdown-content { background-color: #2a2a2a; } .dropdown-content li>span { color: #fff !important; }
        .select-wrapper.active .caret { color: #00e5ff !important; } .select-wrapper .caret { color: #bdbdbd !important; }

        .banner { padding: 8px 15px; margin: 15px 0; border-radius: 8px; text-align: center; }
        .banner-ok { background: rgba(76, 175, 80, 0.25); color: #c8e6c9; border: 1px solid rgba(129, 199, 132, 0.5); }
        .banner-err { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border: 1px solid rgba(239, 154, 154, 0.5); }
        .banner-info { background: rgba(2, 119, 189, 0.25); color: #b3e5fc; border: 1px solid rgba(79, 195, 247, 0.5); }
        
        table { border: none; } table.striped tbody tr:nth-child(odd) { background: rgba(255, 255, 255, .05); }
        th { border-bottom: 1px solid rgba(255, 255, 255, 0.3); } td, th { padding: 15px 10px; }

        .pager a { color: #fff; } .btn-flat { color: #fff; }
        .btn-flat.disabled { color: #777 !important; }
        .modal { background-color: #212121; color: #fff; } .modal .modal-content h4 { text-shadow: 0 0 5px rgba(0, 229, 255, 0.5); } .modal .modal-footer { background-color: #303030; }
        
        .main-nav-btn { background-color: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2); transition: background-color 0.3s; }
        .main-nav-btn:hover { background-color: rgba(255, 255, 255, 0.2); }
        .main-nav-btn.active { background-color: #00bfa5; color: #121212; border-color: #00bfa5; }
        .main-nav-btn.active i { color: #121212; }
    </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main>
    <div class="container">
        <br>
        <h1 class="center-align white-text site-title" style="font-size: 3.5rem; margin-bottom: 0;">hospital0</h1>
        <h5 class="center-align grey-text text-lighten-3 site-subtitle" style="margin-bottom:5px;">Pharmacy Management System</h5>
        <hr class="white-line">
        
        <div class="row">
            <div class="col s12 m6 l3"><div class="stat-box"><h5 id="stat-sales-this-title">Sales (This Month)</h5><p id="stat-sales-this-value">PKR 0.00</p></div></div>
            <div class="col s12 m6 l3"><div class="stat-box"><h5 id="stat-spent-this-title">Expenses (This Month)</h5><p id="stat-spent-this-value" class="red-text text-lighten-2">PKR 0.00</p></div></div>
            <div class="col s12 m6 l3"><div class="stat-box"><h5 id="stat-account-title">Account (This Month)</h5><p id="stat-account-value" class="green-text text-lighten-2">PKR 0.00</p></div></div>
            <div class="col s12 m6 l3"><div class="stat-box"><h5>Total Stock Value</h5><p id="total-stock-value" class="cyan-text text-lighten-2">PKR 0.00</p></div></div>
        </div>

        <?php if ($flash) : ?>
            <div class="<?= ($flashType === 'err' ? 'banner-err' : 'banner-ok') ?> banner center-align"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>

        <?php if ($invoiceRel) : ?>
            <div class="row center-align glass-card active" style="padding: 1.5rem;">
                <a href="<?= htmlspecialchars($invoiceRel) ?>" target="_blank" class="btn waves-effect waves-light" style="background-color: #00bfa5;"><i class="material-icons left">picture_as_pdf</i>Download Invoice</a>
                <a href="pharmacist.php" class="btn waves-effect waves-light grey">New Order</a>
            </div>
        <?php endif; ?>

        <!-- Main Navigation -->
        <div class="row center-align">
            <a class="btn-large waves-effect waves-light main-nav-btn active" data-target="tabDispense"><i class="material-icons left">shopping_cart</i>Dispense</a>
            <a class="btn-large waves-effect waves-light main-nav-btn" data-target="tabInventory"><i class="material-icons left">inventory_2</i>Inventory</a>
            <a class="btn-large waves-effect waves-light main-nav-btn" data-target="tabSales"><i class="material-icons left">receipt_long</i>Sales & Returns</a>
            <a class="btn-large waves-effect waves-light main-nav-btn" data-target="tabExpenses"><i class="material-icons left">money_off</i>Expenses</a>
        </div>
        
        <!-- Dispense Tab -->
        <div id="tabDispense" class="glass-card active">
            <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Create New Order</h5>
             <div class="banner-info center-align">Select drugs from the left to start creating an order.</div>
            <form method="POST" id="orderForm"><input type="hidden" name="action" value="dispense">
                <div class="row">
                    <div class="col s12 m6">
                        <h6 class="white-text">Search & Select</h6>
                        <div class="input-field"><input id="orderSearch" type="text" placeholder="Search by brand or generic name"></div>
                        <table id="orderTable" class="highlight responsive-table">
                            <thead><tr><th>Brand</th><th>Generic</th><th>Dose</th><th>Stock</th></tr></thead>
                            <tbody></tbody>
                        </table>
                        <div id="pagerOrder" class="pager center-align"></div>
                    </div>
                    <div class="col s12 m6">
                        <h6 class="white-text">Order List</h6>
                        <table id="cartTable" class="striped responsive-table">
                            <thead><tr><th>Drug</th><th>Qty</th><th>Price</th><th></th></tr></thead>
                            <tbody></tbody>
                            <tfoot><tr><th colspan="2" class="right-align">Grand Total (PKR)</th><th id="cartTotal" class="right-align">0.00</th><th></th></tr></tfoot>
                        </table>
                    </div>
                </div>
                <div class="row center-align">
                    <button class="btn waves-effect waves-light" type="submit" style="background-color: #00bfa5;"><i class="material-icons left">point_of_sale</i>Dispense & Invoice</button>
                    <a href="dashboard.php" class="btn grey waves-effect waves-light">Go Back</a>
                </div>
            </form>
        </div>
        
        <!-- Inventory Management Tab -->
        <div id="tabInventory" class="glass-card">
            <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Inventory Management</h5>
            <div class="row">
                <div class="col s12 m6">
                    <h6 class="white-text">Add New Drug</h6>
                    <form method="POST"><input type="hidden" name="action" value="add_drug">
                        <div class="input-field"><input name="brand" required><label class="active">Brand</label></div>
                        <div class="input-field"><input name="generic" required><label class="active">Generic</label></div>
                        <div class="input-field"><input name="dosage" required><label class="active">Dosage</label></div>
                        <div class="input-field"><input name="qty" type="number" min="0" required><label class="active">Quantity</label></div>
                        <div class="input-field"><input name="sp" type="text" inputmode="decimal" pattern="[0-9.]*" step="0.01" min="0" placeholder="PKR" required><label class="active">Selling Price</label></div>
                        <button class="btn waves-effect waves-light" type="submit" style="background-color:#00bfa5;">Save Drug</button>
                    </form>
                </div>
                <div class="col s12 m6">
                    <h6 class="white-text">View & Delete Drugs</h6>
                    <form method="POST" id="delForm"><input type="hidden" name="action" value="delete_drugs">
                        <div class="input-field"><input id="searchView" type="text" placeholder="Search to view or select for deletion"></div>
                        <table id="viewTable" class="striped responsive-table">
                           <thead><tr><th><label><input type="checkbox" id="selectAllDelete" /><span></span></label></th><th>Brand</th><th>Rack</th><th>Qty</th><th>Price</th><th>Actions</th></tr></thead>
                           <tbody></tbody>
                        </table>
                        <div id="pagerView" class="pager center-align"></div>
                        <button class="btn red waves-effect waves-light" type="submit" onclick="return confirm('Delete selected drugs? This cannot be undone.');"><i class="material-icons left">delete_forever</i>Delete Selected</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sales & Returns Tab -->
        <div id="tabSales" class="glass-card">
             <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Sales & Returns</h5>
             <div class="row">
                 <div class="col s12 m6">
                     <h6 class="white-text">Recent Sales Invoices</h6>
                     <table id="recentSalesTable" class="striped responsive-table">
                         <thead><tr><th>Date</th><th>Serial</th><th>Amount</th><th>User</th><th>Invoice</th></tr></thead>
                         <tbody></tbody>
                     </table>
                     <div id="pagerRecentSales" class="pager center-align"></div>
                 </div>
                 <div class="col s12 m6">
                     <h6 class="white-text">Return Items from a Sale</h6>
                     <div class="input-field"><i class="material-icons prefix">search</i><input id="returnSerialSearch" type="text" placeholder="Enter Invoice Serial Number"></div>
                     <div id="returnItemsResult"></div>
                 </div>
             </div>
        </div>

        <!-- Expenses Tab -->
        <div id="tabExpenses" class="glass-card">
            <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Expenses</h5>
            <div class="row">
                <div class="col s12 m5">
                    <h6 class="white-text">Add New Expense</h6>
                    <form method="POST"><input type="hidden" name="action" value="add_expense">
                        <div class="input-field"><input id="expense_desc" name="description" type="text" required><label for="expense_desc">Description</label></div>
                        <div class="input-field"><input id="expense_amount" name="amount" type="number" step="0.01" min="0.01" required><label for="expense_amount">Amount (PKR)</label></div>
                        <button class="btn waves-effect waves-light" type="submit" style="background-color:#00bfa5;">Add Expense</button>
                    </form>
                </div>
                <div class="col s12 m7">
                    <div class="row" style="margin-bottom:0; align-items:center;">
                        <div class="col s6"><h6 class="white-text">Expense History</h6></div>
                        <div class="col s6"><div class="input-field" style="margin-top:0;"><select id="expenseMonthSelect">
                            <?php foreach (array_keys($monthlyFinancials) as $monthValue): ?>
                            <option value="<?= $monthValue ?>"><?= date('F Y', strtotime($monthValue)) ?></option>
                            <?php endforeach; ?>
                        </select></div></div>
                    </div>
                    <div id="expenseList"></div>
                    <div id="pagerExpenses" class="pager center-align"></div>
                </div>
            </div>
        </div>

    </div>
</main>

<div id="rackModal" class="modal modal-fixed-footer">
    <form method="POST"><input type="hidden" name="action" value="update_rack"><input type="hidden" name="drug_id" id="modal_drug_id">
        <div class="modal-content">
            <h4 class="white-text">Add/Edit Rack Information</h4>
            <p class="grey-text text-lighten-1">Enter rack number(s). For multiple, separate with a comma (e.g., 4, 12, 23).</p>
            <div class="input-field"><input id="modal_rack_info" name="rack_info" type="text" class="white-text" pattern="[0-9, ]*"><label for="modal_rack_info">Rack Number(s)</label></div>
        </div>
        <div class="modal-footer"><button type="submit" class="waves-effect waves-green btn-flat">Save</button><a href="#!" class="modal-close waves-effect waves-red btn-flat">Cancel</a></div>
    </form>
</div>
    
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
    // Main application script
    document.addEventListener('DOMContentLoaded', () => {
        // Initialization
        M.FormSelect.init(document.querySelectorAll('select'));
        M.Modal.init(document.querySelectorAll('.modal'));
        const esc = t => { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; };
        const per = 20;
        const dAll = <?= json_encode($drugs) ?>;
        const recentSalesAll = <?= json_encode($recentSales) ?>;
        const allExpenses = <?= json_encode($allExpenses) ?>;
        const monthlyFinancials = <?= json_encode($monthlyFinancials) ?>;

        // --- Navigation Logic ---
        const navButtons = document.querySelectorAll('.main-nav-btn');
        const contentPanes = document.querySelectorAll('.glass-card');
        navButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                navButtons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                contentPanes.forEach(pane => {
                    if (pane.id === btn.dataset.target) {
                        pane.classList.add('active');
                    } else {
                        pane.classList.remove('active');
                    }
                });
            });
        });
        
        // --- Shared Pager ---
        function pager(el, total, cur, cb) { el.innerHTML = ''; if (total <= 1) return; const mk = (t,p) => { const a=document.createElement('a'); a.href='#'; a.textContent=t; a.className='btn-flat white-text'; if(p===cur)a.classList.add('disabled'); a.onclick=e=>{e.preventDefault();if(p!==cur)cb(p);}; return a; }; el.appendChild(mk('«', Math.max(1,cur-1))); el.appendChild(document.createTextNode(` ${cur}/${total} `)); el.appendChild(mk('»', Math.min(total,cur+1))); }

        // --- Summary Stats ---
        const totalValue = dAll.reduce((s,d) => s+(Number(d.quantity)*Number(d.selling_price)),0);
        document.getElementById('total-stock-value').textContent = `PKR ${totalValue.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
        const currentMonthKey = new Date().getFullYear() + '-' + ('0' + (new Date().getMonth() + 1)).slice(-2);
        const currentMonthData = monthlyFinancials[currentMonthKey] || { sales: 0, expenses: 0, account: 0 };
        document.getElementById('stat-sales-this-value').textContent = `PKR ${Number(currentMonthData.sales).toLocaleString('en-US',{minimumFractionDigits:2})}`;
        document.getElementById('stat-spent-this-value').textContent = `PKR ${Number(currentMonthData.expenses).toLocaleString('en-US',{minimumFractionDigits:2})}`;
        const accountEl = document.getElementById('stat-account-value');
        accountEl.textContent = `PKR ${Number(currentMonthData.account).toLocaleString('en-US',{minimumFractionDigits:2})}`;
        accountEl.classList.toggle('red-text', currentMonthData.account < 0); accountEl.classList.toggle('green-text', currentMonthData.account >= 0);

        // --- Inventory View/Delete Logic ---
        const bodyV = document.querySelector('#viewTable tbody'); let pgV = 1; let filteredV = dAll;
        function renderV(p) { bodyV.innerHTML = ''; filteredV.slice((p-1)*per,p*per).forEach(d=>{ const r=d.rack?esc(d.rack):''; const btn=`<a href="#!" class="btn-small waves-effect waves-light ${r?'green':'blue-grey'} rack-btn" data-id="${d.id}" data-rack="${r}"><i class="material-icons">edit</i></a>`; bodyV.insertAdjacentHTML('beforeend',`<tr><td><label><input type="checkbox" name="del[]" value="${d.id}" class="del-check"/><span></span></label></td><td>${esc(d.brand_name)}</td><td>${r}</td><td>${d.quantity}</td><td>${Number(d.selling_price).toFixed(2)}</td><td>${btn}</td></tr>`); }); pager(document.getElementById('pagerView'),Math.ceil(filteredV.length/per),p, n=>{pgV=n; renderV(n);}); }
        document.getElementById('searchView').onkeyup = e => { const q = e.target.value.toLowerCase(); filteredV = dAll.filter(x => x.brand_name.toLowerCase().includes(q) || x.generic_name.toLowerCase().includes(q)); renderV(1); };
        document.getElementById('selectAllDelete').onchange = e => { bodyV.querySelectorAll('.del-check').forEach(c => c.checked = e.target.checked); };
        renderV(pgV);
        const rackModal=M.Modal.getInstance(document.getElementById('rackModal'));
        bodyV.addEventListener('click',e=>{const b=e.target.closest('.rack-btn');if(b){e.preventDefault();document.getElementById('modal_drug_id').value=b.dataset.id;document.getElementById('modal_rack_info').value=b.dataset.rack;M.updateTextFields();rackModal.open();}});

        // --- Dispense Logic ---
        const bodyO = document.querySelector('#orderTable tbody'); let pgO = 1; const cart = document.querySelector('#cartTable tbody'); let filteredO = dAll;
        function updateCartTotal(){ const t=document.getElementById('cartTotal'); let gt=0; cart.querySelectorAll('tr').forEach(r=>{gt+=parseFloat(r.cells[2].textContent)||0;}); t.textContent=gt.toFixed(2); }
        function hookOrderRows(){ bodyO.querySelectorAll('tr').forEach(tr=>{tr.onclick=()=>{const n=tr.dataset.name,id=tr.dataset.id,sp=parseFloat(tr.dataset.sp),s=parseInt(tr.dataset.stock,10); const q=parseInt(prompt(`Quantity for:\n${n}\n(Available: ${s})`),10); if(!q||q<1)return; if(q>s){M.toast({html:`Error: Requested ${q}, but only ${s} available.`});return;} const tp=(q*sp).toFixed(2); cart.insertAdjacentHTML('beforeend',`<tr><td>${esc(n)}<input type="hidden" name="order[${cart.rows.length}][id]" value="${id}"><input type="hidden" name="order[${cart.rows.length}][q]" value="${q}"></td><td>${q}</td><td>${tp}</td><td><a href="#!" class="rm red-text text-lighten-2"><i class="material-icons">clear</i></a></td></tr>`); updateCartTotal();};});}
        function renderO(p){ bodyO.innerHTML = ''; filteredO.slice((p-1)*per,p*per).forEach(d=>{ bodyO.insertAdjacentHTML('beforeend',`<tr data-id="${d.id}" data-name="${esc(d.brand_name+' ('+d.generic_name+') '+d.dosage)}" data-sp="${d.selling_price}" data-stock="${d.quantity}"><td>${esc(d.brand_name)}</td><td>${esc(d.generic_name)}</td><td>${esc(d.dosage)}</td><td>${d.quantity}</td></tr>`); }); pager(document.getElementById('pagerOrder'),Math.ceil(filteredO.length/per),p,n=>{pgO=n; renderO(n);}); hookOrderRows(); }
        renderO(pgO);
        document.getElementById('orderSearch').onkeyup=e=>{const q=e.target.value.toLowerCase(); filteredO = dAll.filter(x=>x.brand_name.toLowerCase().includes(q) || x.generic_name.toLowerCase().includes(q)); renderO(1);};
        cart.onclick=e=>{if(e.target.closest('.rm')){e.target.closest('tr').remove(); updateCartTotal();}};
        document.getElementById('orderForm').addEventListener('submit',function(e){if(cart.rows.length===0){M.toast({html:'Order list is empty.'});e.preventDefault();return;} e.preventDefault(); const pName=prompt("Patient's Full Name (optional):"); let hInput=this.querySelector('input[name="patient_name"]'); if(!hInput){hInput=document.createElement('input'); hInput.type='hidden'; hInput.name='patient_name'; this.appendChild(hInput);} hInput.value=pName||''; this.submit();});

        // --- Sales & Returns Logic ---
        const bodyRS = document.querySelector('#recentSalesTable tbody'); let pgRS = 1; const perRS = 10;
        function renderRS(p,arr){ bodyRS.innerHTML=''; arr.slice((p-1)*perRS,p*perRS).forEach(d=>{const dt=new Date(d.datetime).toLocaleString('en-GB'); const pPath=`pharmacy/invoice_${d.description}.pdf`; bodyRS.insertAdjacentHTML('beforeend',`<tr><td>${dt}</td><td>${esc(d.description)}</td><td>${Number(d.amount).toFixed(2)}</td><td>${esc(d.user)}</td><td><a href="${pPath}" target="_blank" class="btn-small waves-effect waves-light"><i class="material-icons">picture_as_pdf</i></a></td></tr>`);}); pager(document.getElementById('pagerRecentSales'),Math.ceil(arr.length/perRS),p,n=>{pgRS=n;renderRS(n,arr);});}
        renderRS(pgRS, recentSalesAll);
        const returnSearch=document.getElementById('returnSerialSearch'), returnResult=document.getElementById('returnItemsResult'); let sTimeout;
        returnSearch.addEventListener('keyup',()=>{clearTimeout(sTimeout); const s=returnSearch.value.trim(); if(s.length<3){returnResult.innerHTML='';return;} returnResult.innerHTML='<p class="white-text center-align">Searching...</p>'; sTimeout=setTimeout(()=>{ fetch(`api_fetch_sale.php?serial=${s}`).then(r=>r.json()).then(d=>{if(d.error){returnResult.innerHTML=`<p class="red-text center-align">${d.error}</p>`;}else{let trs='';d.items.forEach(i=>{const avail=i.quantity_sold-i.quantity_returned;if(avail>0){trs+=`<tr><td>${esc(i.brand_name)}</td><td>${i.quantity_sold}</td><td>${i.quantity_returned}</td><td>${Number(i.price_per_item).toFixed(2)}</td><td><div class="input-field" style="margin-top:0;"><input type="number" name="returns[${i.sale_item_id}][qty]" min="0" max="${avail}" value="0" style="width:70px;"></div></td></tr>`;}}); if(trs===''){returnResult.innerHTML='<p class="yellow-text center-align">All items from this invoice already returned.</p>';}else{returnResult.innerHTML=`<form method="POST"><input type="hidden" name="action" value="return_items"><input type="hidden" name="return_serial" value="${esc(s)}"><p class="white-text"><strong>Date:</strong> ${new Date(d.datetime).toLocaleString('en-GB')}</p><table class="striped responsive-table"><thead><tr><th>Drug</th><th>Sold</th><th>Returned</th><th>Price</th><th>Return Qty</th></tr></thead><tbody>${trs}</tbody></table><br><button type="submit" class="btn orange">Process Return</button></form>`;}}}).catch(err=>{console.error('Fetch Error:',err); returnResult.innerHTML=`<p class="red-text center-align">Error fetching data.</p>`;});},500);});

        // --- Expenses Logic ---
        const expListEl=document.getElementById('expenseList'), monSel=document.getElementById('expenseMonthSelect'); let pgExp=1; const perExp=5;
        function renderExpenses(p,arr){expListEl.innerHTML='';if(arr.length===0){expListEl.innerHTML='<p class="grey-text center-align">No expenses for this month.</p>';document.getElementById('pagerExpenses').innerHTML='';return;} arr.slice((p-1)*perExp,p*perExp).forEach(ex=>{const d=new Date(ex.expense_date).toLocaleDateString('en-GB');expListEl.insertAdjacentHTML('beforeend',`<div class="card-panel" style="background:rgba(0,0,0,0.2);"><div class="row" style="margin:0;align-items:center;"><div class="col s8"><strong>${esc(ex.description)}</strong><br><small class="grey-text">By: ${esc(ex.user)} on ${d}</small></div><div class="col s4 right-align"><strong class="red-text text-lighten-2">- PKR ${Number(ex.amount).toFixed(2)}</strong></div></div></div>`);}); pager(document.getElementById('pagerExpenses'),Math.ceil(arr.length/perExp),p,n=>{pgExp=n;renderExpenses(n,arr);});}
        function updateExpenseTab(m){const d=monthlyFinancials[m];const lmDate=new Date(m+'-01');lmDate.setMonth(lmDate.getMonth()-1);const lmKey=lmDate.getFullYear()+'-'+('0'+(lmDate.getMonth()+1)).slice(-2);const lmData=monthlyFinancials[lmKey]||{expenses:0};const mLbl=new Date(m+'-01').toLocaleString('default',{month:'long'});const lmLbl=lmDate.toLocaleString('default',{month:'long'}); document.getElementById('stat-spent-this-title').textContent=`Expenses (${mLbl})`;document.getElementById('stat-spent-this-value').textContent=`PKR ${Number(d.expenses).toLocaleString('en-US',{minimumFractionDigits:2})}`;const expForMonth=allExpenses.filter(ex=>ex.expense_date.startsWith(m));renderExpenses(1,expForMonth);}
        monSel.addEventListener('change',(e)=>{updateExpenseTab(e.target.value);}); updateExpenseTab(monSel.value);
    });
</script>
</body>
</html>