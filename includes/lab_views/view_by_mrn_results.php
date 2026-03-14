<?php
// Prevent direct access
if (!isset($pdo) || !isset($action) || $action !== 'view_by_mrn_results') die('Access denied');

global $lookup_mrn, $awaited_reports, $issued_reports, $page, $totalPages, $totalItems, $message, $error, $can_issue_reports; // Make variables accessible

// Fetch patient name again for display
$patientName = 'N/A';
if (!empty($lookup_mrn)) {
    $stmtP = $pdo->prepare("SELECT full_name FROM patients WHERE mrn = ? LIMIT 1");
    $stmtP->execute([$lookup_mrn]);
    $patientName = $stmtP->fetchColumn();
     if(!$patientName) $patientName = "[MRN Found, Name Missing!]";
}

?>
<div class="card">
     <div class="card-content">
         <a href="?action=view_by_mrn_form" class="btn grey waves-effect waves-light right" style="margin-top: -10px;">
            <i class="material-icons left">arrow_back</i>New Search
        </a>
        <span class="card-title"><i class="material-icons">list_alt</i>Lab Records for MRN: <?= htmlspecialchars($lookup_mrn) ?></span>
        <p><strong>Patient:</strong> <?= htmlspecialchars($patientName) ?></p>

        <?php if ($message): ?><p class="msg-ok" style="margin-top:15px;"><i class="material-icons">info</i><?= htmlspecialchars($message) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="msg-err" style="margin-top:15px;"><i class="material-icons">warning</i><?= htmlspecialchars($error) ?></p><?php endif; ?>
     </div>
</div>


<div class="card">
    <div class="card-content">
        <span class="section-title"><i class="material-icons left tiny">hourglass_top</i>Reports Awaited (Paid Orders)</span>
        <?php if (empty($awaited_reports)): ?>
            <p class="center-align grey-text">No reports awaiting results for this patient.</p>
        <?php else: ?>
            <table class="striped responsive-table highlight">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Order Date</th>
                        <th>Test Name</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($awaited_reports as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['order_id']) ?></td>
                            <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($item['order_date']))) ?></td>
                            <td><?= htmlspecialchars($item['test_name']) ?></td>
                            <td>
                                <?php if ($can_issue_reports): ?>
                                <a href="?action=issue_report_form&item_id=<?= $item['item_id'] ?>" class="btn-small waves-effect waves-light teal">
                                   <i class="material-icons left">edit_note</i> Enter/View Results
                                </a>
                                <?php else: ?>
                                 <span class="grey-text">Awaiting Results</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
             <?php // Optional: Pagination for awaited reports if needed (would require separate total/page vars) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-content">
         <span class="section-title"><i class="material-icons left tiny">check_circle</i>Reports Issued</span>
         <?php if (empty($issued_reports)): ?>
             <p class="center-align grey-text">No issued reports found for this patient.</p>
         <?php else: ?>
            <table class="striped responsive-table highlight">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Test Name</th>
                        <th>Issued Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($issued_reports as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['order_id']) ?></td>
                            <td><?= htmlspecialchars($item['test_name']) ?></td>
                            <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($item['report_issued_at']))) ?></td>
                            <td>
                                <?php if (!empty($item['report_pdf_path']) && file_exists(__DIR__ . '/../' . $item['report_pdf_path'])): // Check file exists relative to labs.php ?>
                                    <a href="<?= htmlspecialchars($item['report_pdf_path']) ?>" target="_blank" class="btn-small waves-effect waves-light green">
                                        <i class="material-icons left">picture_as_pdf</i> View Report
                                    </a>
                                <?php else: ?>
                                    <span class="red-text">PDF Missing!</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

             <?php // Render Pagination for issued reports
               echo renderPagination($page, $totalPages, '?action=view_by_mrn_results&mrn='.urlencode($lookup_mrn));
            ?>
         <?php endif; ?>
    </div>
</div>