<?php
// Prevent direct access
if (!isset($pdo) || !isset($action) || $action !== 'add_test_form') die('Access denied');

global $edit_test, $error; // Make variables accessible
$is_editing = ($edit_test !== null && isset($edit_test['test_id']));
?>

<div class="card">
    <div class="card-content">
        <span class="card-title">
            <i class="material-icons">biotech</i>
            <?= $is_editing ? 'Edit Lab Test #' . htmlspecialchars($edit_test['test_id']) : 'Add New Lab Test' ?>
        </span>

        <?php if ($error): ?><p class="msg-err"><i class="material-icons">error</i><?= htmlspecialchars($error) ?></p><?php endif; ?>

        <form method="POST" action="?action=save_test">
            <?php if ($is_editing): ?>
                <input type="hidden" name="test_id" value="<?= htmlspecialchars($edit_test['test_id']) ?>">
            <?php endif; ?>

            <div class="row">
                <div class="input-field col s12 m8">
                    <i class="material-icons prefix">label</i>
                    <input id="name" name="name" type="text" class="validate white-text" required value="<?= htmlspecialchars($edit_test['name'] ?? '') ?>">
                    <label for="name">Test Name</label>
                </div>
                 <div class="input-field col s12 m4">
                    <i class="material-icons prefix">attach_money</i>
                    <input id="price" name="price" type="number" step="0.01" min="0" class="validate white-text" required value="<?= htmlspecialchars($edit_test['price'] ?? '0.00') ?>">
                    <label for="price">Price (PKR)</label>
                </div>
            </div>
            <div class="row">
                <div class="input-field col s12">
                     <i class="material-icons prefix">description</i>
                    <textarea id="details" name="details" class="materialize-textarea white-text" data-length="1000"><?= htmlspecialchars($edit_test['details'] ?? '') ?></textarea>
                    <label for="details">Details (Reference Range, Units, Notes etc.)</label>
                </div>
            </div>
            <div class="row center-align" style="margin-top: 20px;">
                 <a href="?action=view_tests" class="btn waves-effect waves-light grey">
                     <i class="material-icons left">cancel</i>Cancel
                 </a>
                <button type="submit" class="btn waves-effect waves-light teal">
                    <i class="material-icons left">save</i><?= $is_editing ? 'Update Test' : 'Save Test' ?>
                </button>
            </div>
        </form>
    </div>
</div>