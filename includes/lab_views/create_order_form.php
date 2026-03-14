<?php
// Prevent direct access
if (!isset($pdo) || !isset($action) || $action !== 'create_order_form') die('Access denied');

global $tests; // Make variables accessible
?>
<div class="card">
    <div class="card-content">
        <span class="card-title"><i class="material-icons">playlist_add</i>Create New Lab Order</span>

        <form method="POST" action="?action=save_order" id="createOrderForm">
            <div class="row" style="margin-bottom: 0;">
                <div class="input-field col s12 m6">
                    <i class="material-icons prefix">badge</i>
                    <input id="mrn" name="mrn" type="text" class="validate white-text" required onblur="lookupPatientSimple()">
                    <label for="mrn">Patient MRN *</label>
                    <span id="patientNameDisplay" class="lookup-box helper-text"></span>
                </div>
            </div>

            <div class="row">
                <div class="col s12">
                    <h6 class="section-title white-text">Select Tests *</h6>
                    <?php if (empty($tests)): ?>
                        <p class="red-text">No lab tests found in the system. Please add tests first.</p>
                    <?php else: ?>
                        <div id="testSelection" style="max-height: 300px; overflow-y: auto; padding: 10px; border: 1px solid #555; border-radius: 4px;">
                             <table class="striped highlight">
                                 <thead>
                                     <tr><th>Select</th><th>Test Name</th><th>Price</th></tr>
                                 </thead>
                                 <tbody>
                                <?php foreach ($tests as $test): ?>
                                    <tr>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="test_ids[]" value="<?= $test['test_id'] ?>" class="filled-in" data-price="<?= htmlspecialchars($test['price']) ?>" />
                                                <span></span>
                                            </label>
                                        </td>
                                        <td><?= htmlspecialchars($test['name']) ?></td>
                                        <td><?= htmlspecialchars(number_format($test['price'], 2)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                 </tbody>
                             </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

             <div class="row right-align" style="margin-top: 15px;">
                <span class="white-text" style="font-size: 1.2em; margin-right: 10px;">Order Total:</span>
                <strong id="orderTotalDisplay" class="cyan-text text-lighten-1" style="font-size: 1.3em;">PKR 0.00</strong>
             </div>


            <div class="row center-align" style="margin-top: 20px;">
                <button type="submit" class="btn-large waves-effect waves-light teal" <?= empty($tests) ? 'disabled' : '' ?>>
                    <i class="material-icons left">add_shopping_cart</i>Create Order
                </button>
            </div>
        </form>
    </div>
</div>