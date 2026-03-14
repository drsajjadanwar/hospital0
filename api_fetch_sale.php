<?php
// /public/api_fetch_sale.php
session_start();
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || !in_array((int)$_SESSION['user']['group_id'], [1, 7], true)) {
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

$serial = trim($_GET['serial'] ?? '');
if (empty($serial)) {
    echo json_encode(['error' => 'Serial number is required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            si.sale_item_id, 
            si.drug_id, 
            si.quantity_sold, 
            si.quantity_returned, 
            si.price_per_item, 
            di.brand_name, 
            di.generic_name, 
            di.dosage,
            pl.datetime
        FROM pharmacy_sales_items si
        JOIN drug_inventory di ON si.drug_id = di.id
        JOIN pharmacyledger pl ON si.serial = pl.description
        WHERE si.serial = ?
    ");
    $stmt->execute([$serial]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        echo json_encode(['error' => 'No sale found with that serial number.']);
        exit;
    }

    // Since patient name is not stored with the sale, we can't retrieve it here.
    // This could be a future improvement by adding a patient_mrn to the pharmacyledger.
    $patientName = "N/A"; 

    echo json_encode([
        'items' => $items,
        'datetime' => $items[0]['datetime'] ?? null,
        'patient_name' => $patientName
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
