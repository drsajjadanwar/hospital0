<?php
// Prevent direct access (optional check)
if (!isset($pdo) || !isset($action) || $action !== 'view_tests') die('Access denied');

global $page, $totalPages, $tests, $can_manage_tests; // Make variables from controller accessible
?>

<div class="card">
    <div class="card-content">
        <div class="row" style="margin-bottom: 10px;">
            <div class="col s12 m6">
                <span class="card-title"><i class="material-icons">science</i>Manage Lab Tests</span>
            </div>
            <div class="col s12 m6 right-align">
                <?php if ($can_manage_tests): ?>
                    <a href="?action=add_test_form" class="btn waves-effect waves-light teal">
                        <i class="material-icons left">add</i>Add New Test
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($tests)): ?>
            <p class="center-align">No lab tests found.</p>
        <?php else: ?>
            <table class="striped responsive-table highlight">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Test Name</th>
                        <th>Price (PKR)</th>
                        <th>Details/Reference</th>
                         <?php if ($can_manage_tests): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tests as $test): ?>
                        <tr>
                            <td><?= htmlspecialchars($test['test_id']) ?></td>
                            <td><?= htmlspecialchars($test['name']) ?></td>
                            <td><?= htmlspecialchars(number_format($test['price'], 2)) ?></td>
                            <td><?= nl2br(htmlspecialchars($test['details'] ?? 'N/A')) ?></td>
                            <?php if ($can_manage_tests): ?>
                            <td>
                                <a href="?action=add_test_form&test_id=<?= $test['test_id'] ?>" class="btn-floating btn-small waves-effect waves-light blue" title="Edit Test">
                                    <i class="material-icons">edit</i>
                                </a>
                                <a href="?action=delete_test&test_id=<?= $test['test_id'] ?>" class="btn-floating btn-small waves-effect waves-light red" title="Delete Test" onclick="return confirm('Are you sure you want to delete this test? This cannot be undone and might fail if the test is used in orders.');">
                                    <i class="material-icons">delete</i>
                                </a>
                            </td>
                             <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php // Render Pagination
               echo renderPagination($page, $totalPages, '?action=view_tests');
            ?>
        <?php endif; ?>
    </div>
</div>