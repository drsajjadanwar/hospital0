<?php
// Prevent direct access
if (!isset($pdo) || !isset($action) || $action !== 'issue_report_form' || !isset($item_details)) die('Access denied');

global $item_details, $patient_details, $userId, $error, $message; // Make variables accessible
$result_exists = isset($item_details['result_id']) && $item_details['result_id'] > 0;
?>

<div class="card">
    <div class="card-content">
         <span class="card-title">
             <i class="material-icons">biotech</i>
             <?= $result_exists ? 'View/Edit' : 'Enter' ?> Results for: <?= htmlspecialchars($item_details['test_name']) ?>
         </span>
         <a href="?action=issue_report_lookup&mrn=<?= urlencode($patient_details['mrn'] ?? '') ?>" class="btn grey waves-effect waves-light right" style="margin-top: -50px;">
            <i class="material-icons left">arrow_back</i>Back to Pending List
        </a>

        <?php if ($error): ?><p class="msg-err"><i class="material-icons">error</i><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <?php if ($message): ?><p class="msg-ok"><i class="material-icons">check</i><?= htmlspecialchars($message) ?></p><?php endif; ?>

        <div class="row section grey darken-2" style="padding: 10px 5px; border-radius: 4px; margin-bottom: 20px;">
             <div class="col s12 m6">
                 <strong>Patient:</strong> <?= htmlspecialchars($patient_details['full_name'] ?? 'N/A') ?><br>
                 <strong>MRN:</strong> <?= htmlspecialchars($patient_details['mrn'] ?? 'N/A') ?><br>
                 <strong>Age/Gender:</strong> <?= htmlspecialchars($patient_details['age'] ?? '?') ?> <?= htmlspecialchars($patient_details['age_unit'] ?? '') ?> / <?= htmlspecialchars($patient_details['gender'] ?? '?') ?>
             </div>
             <div class="col s12 m6">
                 <strong>Order ID:</strong> <?= htmlspecialchars($item_details['order_id'] ?? 'N/A') ?><br>
                 <strong>Order Date:</strong> <?= htmlspecialchars(date('Y-m-d H:i', strtotime($item_details['order_date'] ?? ''))) ?><br>
                  <strong>Item ID:</strong> <?= htmlspecialchars($item_details['item_id'] ?? 'N/A') ?>
             </div>
         </div>

        <?php if (!empty($item_details['test_details'])): ?>
        <div class="row">
            <div class="col s12">
                <p><strong>Reference Information / Units:</strong></p>
                <div style="background: #555; padding: 8px; border-radius: 3px; font-size: 0.9em; white-space: pre-wrap;"><?= htmlspecialchars($item_details['test_details']) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" action="?action=save_report_results">
            <input type="hidden" name="item_id" value="<?= htmlspecialchars($item_details['item_id']) ?>">
            <?php if ($result_exists): ?>
                <input type="hidden" name="result_id" value="<?= htmlspecialchars($item_details['result_id']) ?>">
            <?php endif; ?>

            <div class="row">
                <div class="input-field col s12">
                    <i class="material-icons prefix">science</i>
                    <textarea id="result_value" name="result_value" class="materialize-textarea white-text" required><?= htmlspecialchars($item_details['result_value'] ?? '') ?></textarea>
                    <label for="result_value" class="<?= !empty($item_details['result_value']) ? 'active' : '' ?>">Result Value(s) *</label>
                </div>
            </div>
             <div class="row">
                <div class="input-field col s12">
                    <i class="material-icons prefix">notes</i>
                    <textarea id="result_notes" name="result_notes" class="materialize-textarea white-text" data-length="500"><?= htmlspecialchars($item_details['result_notes'] ?? '') ?></textarea>
                    <label for="result_notes" class="<?= !empty($item_details['result_notes']) ? 'active' : '' ?>">Notes (Optional)</label>
                </div>
            </div>
             <div class="row">
                <div class="col s12">
                     <label>
                        <input type="checkbox" name="is_abnormal" value="1" class="filled-in" <?= !empty($item_details['is_abnormal']) ? 'checked' : '' ?> />
                        <span>Mark as Abnormal</span>
                    </label>
                </div>
            </div>

             <div class="row center-align" style="margin-top: 20px;">
                <button type="submit" class="btn waves-effect waves-light blue">
                    <i class="material-icons left">save</i>Save Results
                </button>
                <?php // Show Generate PDF button only if results are saved ?>
                <?php if ($result_exists): ?>
                    <a href="?action=generate_report_pdf&item_id=<?= htmlspecialchars($item_details['item_id']) ?>" target="_blank" class="btn waves-effect waves-light green">
                        <i class="material-icons left">picture_as_pdf</i>Generate & Issue Report PDF
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>