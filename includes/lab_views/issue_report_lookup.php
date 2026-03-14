<?php
// Prevent direct access
if (!isset($pdo) || !isset($action) || $action !== 'issue_report_lookup') die('Access denied');

global $lookup_mrn, $results_to_issue, $page, $totalPages, $totalItems, $error, $message; // Make variables accessible
?>
<div class="card">
    <div class="card-content">
        <span class="card-title"><i class="material-icons">summarize</i>Issue Report - Find Patient Tests</span>

        <form method="POST" action="?action=issue_report_lookup">
            <div class="row valign-wrapper" style="margin-bottom: 10px;">
                <div class="input-field col s12 m6">
                    <i class="material-icons prefix">badge</i>
                    <input id="mrn" name="mrn" type="text" class="validate white-text" required value="<?= htmlspecialchars($lookup_mrn ?? '') ?>" onblur="lookupPatientSimple()">
                    <label for="mrn">Enter Patient MRN</label>
                    <span id="patientNameDisplay" class="lookup-box helper-text"></span>
                </div>
                <div class="col s12 m6">
                     <button type="submit" class="btn waves-effect waves-light" style="margin-top: 10px;">
                        <i class="material-icons left">search</i>Find Pending Reports
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>


<?php if (!empty($lookup_mrn) && empty($error) && isset($results_to_issue)): // Only show results if MRN was searched and no error occurred ?>
<div class="card">
    <div class="card-content">
        <span class="card-title"><i class="material-icons">pending_actions</i>Pending Paid Tests for MRN: <?= htmlspecialchars($lookup_mrn) ?></span>

        <?php if (empty($results_to_issue)): ?>
            <p class="center-align"><?= isset($message) ? htmlspecialchars($message) : 'No pending tests found matching criteria.' ?></p>
        <?php else: ?>
            <p>Select a test below to enter results and issue the report.</p>
            <table class="striped responsive-table highlight">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Order Date</th>
                        <th>Test Name</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results_to_issue as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['order_id']) ?></td>
                            <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($item['order_date']))) ?></td>
                            <td><?= htmlspecialchars($item['test_name']) ?></td>
                            <td>
                                <?php if ($item['result_id'] && $item['result_date']): ?>
                                    <span class="blue-text text-lighten-2">Results Entered</span>
                                <?php else: ?>
                                    <span class="amber-text text-lighten-1">Awaiting Results</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?action=issue_report_form&item_id=<?= $item['item_id'] ?>" class="btn-small waves-effect waves-light teal">
                                   <i class="material-icons left">edit_note</i> <?= $item['result_id'] ? 'View/Edit Results' : 'Enter Results' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php // Render Pagination
                if (!empty($lookup_mrn)) {
                    echo renderPagination($page, $totalPages, '?action=issue_report_lookup&mrn='.urlencode($lookup_mrn));
                }
            ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>