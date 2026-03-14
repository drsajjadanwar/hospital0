<?php
// /public/labs.php — Simplified Version: 27 Apr 2025
// (Only “View All Tests” & “Manage Tests” tabs retained)

// --------------------------------------------------------------------------
//  Basic error reporting (development‑time helpers)
// --------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/config.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ------------------------------------------------------------------------ */
/*  Authorisation — allow Admin (1) & Lab‑Staff (6) only                   */
/* ------------------------------------------------------------------------ */
$allowedGroups = [1, 6];
if (!isset($_SESSION['user']['group_id']) ||
    !in_array((int)$_SESSION['user']['group_id'], $allowedGroups, true)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied: You do not have permission to access the Laboratory Management System.');
}

/* ------------------------------------------------------------------------ */
/*  Current user (for display in banners etc.)                              */
/* ------------------------------------------------------------------------ */
$currentUserFullName = $_SESSION['user']['full_name']  ?? 'Unknown User';
$currentUsername     = $_SESSION['user']['username']   ?? 'unknown';
$userId              = $_SESSION['user']['user_id']    ?? 0;

if ($userId) {
    try {
        $stmt = $pdo->prepare("SELECT full_name, username FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $currentUserFullName           = $u['full_name'] ?: $u['username'];
            $currentUsername               = $u['username'];
            $_SESSION['user']['full_name'] = $currentUserFullName;
        }
    } catch (Exception $e) {
        error_log('User‑fetch error: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------------ */
/*  Flash messages / active‑tab tracking                                    */
/* ------------------------------------------------------------------------ */
$error_message   = $_SESSION['error_message']   ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

$allowedTabs = ['view_all_tests', 'manage_tests'];
$active_tab  = $_GET['tab'] ?? 'view_all_tests';
if (!in_array($active_tab, $allowedTabs, true)) {
    $active_tab = 'view_all_tests';
}

/* ------------------------------------------------------------------------ */
/*  Data‑fetching                                                           */
/* ------------------------------------------------------------------------ */
$all_tests          = [];
$manage_tests_list  = [];

try {
    $stmt_all = $pdo->query("SELECT test_id, test_name, test_description, test_price FROM lab_tests WHERE is_active = TRUE ORDER BY test_name ASC");
    $all_tests = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message .= ' Error fetching active tests: ' . $e->getMessage();
}

try {
    $stmt_mng = $pdo->query("SELECT test_id, test_name, test_price, is_active FROM lab_tests ORDER BY test_name ASC");
    $manage_tests_list = $stmt_mng->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message .= ' Error fetching tests for management: ' . $e->getMessage();
}

/* ------------------------------------------------------------------------ */
/*  POST request handling (only the actions needed for Manage Tests)        */
/* ------------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // -------------------------------------------------------------
            //  Add a new test (with optional parameters)
            // -------------------------------------------------------------
            case 'add_test':
                $pdo->beginTransaction();

                $test_name  = trim($_POST['test_name'] ?? '');
                $test_price = filter_var($_POST['test_price'] ?? 0, FILTER_VALIDATE_FLOAT);
                $test_desc  = trim($_POST['test_description'] ?? '');

                $param_names  = $_POST['parameter_name']  ?? [];
                $param_ranges = $_POST['reference_range'] ?? [];
                $param_units  = $_POST['result_unit']     ?? [];

                if ($test_name === '' || $test_price === false || $test_price < 0) {
                    throw new Exception('Test Name and a valid positive Price are required.');
                }

                // Insert main test
                $stmt = $pdo->prepare("INSERT INTO lab_tests (test_name, test_description, test_price, is_active) VALUES (?,?,?,TRUE)");
                $stmt->execute([$test_name, $test_desc, $test_price]);
                $test_id = $pdo->lastInsertId();
                if (!$test_id) {
                    throw new Exception('Failed to insert main test record.');
                }

                // Insert any parameters (optional)
                $stmtP = $pdo->prepare("INSERT INTO lab_test_parameters (test_id, parameter_name, reference_range, result_unit) VALUES (?,?,?,?)");
                $inserted_params = 0;
                for ($i = 0; $i < count($param_names); $i++) {
                    $p_name = trim($param_names[$i]  ?? '');
                    $p_rng  = trim($param_ranges[$i] ?? '');
                    $p_uni  = trim($param_units[$i]  ?? '');
                    if ($p_name && $p_rng && $p_uni) {
                        $stmtP->execute([$test_id, $p_name, $p_rng, $p_uni]);
                        $inserted_params++;
                    } elseif ($p_name || $p_rng || $p_uni) {
                        throw new Exception('Parameter #' . ($i + 1) . ' is incomplete. Name, Range & Unit are all required.');
                    }
                }

                $pdo->commit();
                $_SESSION['success_message'] = 'Test “' . htmlspecialchars($test_name) . '”' . ($inserted_params ? " with $inserted_params parameter(s)" : '') . ' added successfully.';
                header('Location: labs.php?tab=manage_tests');
                exit;

            // -------------------------------------------------------------
            //  Toggle test status (activate / deactivate)
            // -------------------------------------------------------------
            case 'toggle_test_status':
                $test_id       = filter_var($_POST['test_id']       ?? 0, FILTER_VALIDATE_INT);
                $currentStatus = filter_var($_POST['current_status'] ?? 0, FILTER_VALIDATE_INT);
                if ($test_id > 0) {
                    $newStatus = $currentStatus ? 0 : 1;
                    $stmt = $pdo->prepare('UPDATE lab_tests SET is_active = ? WHERE test_id = ?');
                    $stmt->execute([$newStatus, $test_id]);
                    $_SESSION['success_message'] = 'Test status updated (' . ($newStatus ? 'activated' : 'deactivated') . ').';
                } else {
                    $_SESSION['error_message'] = 'Invalid Test ID.';
                }
                header('Location: labs.php?tab=manage_tests');
                exit;

            // -------------------------------------------------------------
            //  Delete a test (only if not used in orders)
            // -------------------------------------------------------------
            case 'delete_test':
                $test_id = filter_var($_POST['test_id'] ?? 0, FILTER_VALIDATE_INT);
                if (!$test_id) {
                    throw new Exception('Invalid Test ID.');
                }

                // Check usage in existing order items
                $stmt = $pdo->prepare('SELECT 1 FROM lab_order_items WHERE test_id = ? LIMIT 1');
                $stmt->execute([$test_id]);
                if ($stmt->fetchColumn()) {
                    throw new Exception('Cannot delete test: it is associated with existing orders. Deactivate it instead.');
                }

                $del = $pdo->prepare('DELETE FROM lab_tests WHERE test_id = ?');
                $del->execute([$test_id]);
                if ($del->rowCount()) {
                    $_SESSION['success_message'] = 'Test deleted successfully.';
                } else {
                    throw new Exception('Test not found or could not be deleted.');
                }
                header('Location: labs.php?tab=manage_tests');
                exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = 'Operation failed: ' . $e->getMessage();
        error_log('labs.php POST error: ' . $e->getMessage());
        header('Location: labs.php?tab=' . $active_tab);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>hospital0 - Laboratory Management System — hospital0</title>
    <link rel="icon" href="/media/sitelogo.png">

    <!-- Materialize CSS & Icons (CDN) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        /* Custom dark‑theme & layout tweaks (unchanged from full version) */
        @font-face {
            font-family: 'MyriadPro-Regular';
            src: url('/fonts/MyriadPro-Regular.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
        }
        body {
            font-family: 'MyriadPro-Regular', sans-serif;
            display: flex;
            min-height: 100vh;
            flex-direction: column;
            background-color: #333;
            color: #fff;
        }
        main { flex: 1 0 auto; }
        .container { width: 95%; max-width: 1400px; }
        .white-line-header { width: 60%; height: 1px; background: #757575; border: none; margin: 20px auto 30px auto; }
        h1.site-title { font-size: 2.5rem; font-weight: lighter; color: #eee; margin-bottom: 0; text-align: center; }
        h5.lab-title { color: #fff; text-align: center; font-weight: 300; margin: 0 0 25px; position: relative; display: inline-block; }
        h5.lab-title::after { content: ''; position: absolute; left: 0; bottom: -10px; height: 1px; width: 100%; background: rgba(255,255,255,0.6); }
        .tabs { background: transparent; }
        .tabs .tab a { color: rgba(255,255,255,0.7); }
        .tabs .tab a:hover,
        .tabs .tab a.active { color: #fff; font-weight: bold; }
        .tabs .indicator { background: #1de9b6; }
        .input-field label { color: #bdbdbd; }
        .input-field input:not(.browser-default),
        .input-field textarea.materialize-textarea {
            border-bottom: 1px solid #9e9e9e;
            color: #fff;
        }
        .input-field input:not(.browser-default):focus:not([readonly]),
        .input-field textarea.materialize-textarea:focus:not([readonly]) {
            border-bottom: 1px solid #1de9b6 !important;
            box-shadow: 0 1px 0 0 #1de9b6 !important;
        }
        table { color: #fff; width: 100%; }
        th { font-weight: bold; color: #1de9b6; padding: 12px 8px; }
        td { padding: 10px 8px; }
        tbody tr { border-bottom: 1px solid rgba(255,255,255,0.12); }
        tbody tr:hover { background: rgba(255,255,255,0.05); }
        .btn { background: #1de9b6; color: #333; }
        .btn:hover { background: #00bfa5; }
        .btn.red { background: #e57373 !important; color: #fff !important; }
        .btn.red:hover { background: #ef5350 !important; }
        .btn.green { background: #81c784 !important; color: #fff !important; }
        .btn.green:hover { background: #66bb6a !important; }
        .btn.grey { background: #757575 !important; color: #fff !important; }
        .btn.grey:hover { background: #616161 !important; }
        .banner { color: #fff; padding: 12px 18px; margin: 15px 0; border-radius: 4px; display: flex; align-items: center; }
        .banner-ok { background: #2e7d32; }
        .banner-err { background: #c62828; }
        .banner i { margin-right: 10px; }
        #manage_tests #parameters-section {
            border: 1px solid rgba(255,255,255,0.2);
            padding: 20px;
            margin-top: 15px;
            border-radius: 5px;
            background: rgba(0,0,0,0.05);
        }
        #manage_tests .parameter-set {
            border: 1px dashed rgba(255,255,255,0.3);
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 4px;
            background: rgba(0,0,0,0.1);
            position: relative;
        }
        #manage_tests .removeParameterBtn {
            position: absolute;
            top: 5px;
            right: 5px;
        }

/* keep the box dark in every state */
.input-field input[type=search],
.input-field input[type=search]:focus:not([readonly]) {
    background:rgba(255,255,255,.05) !important;  /* same dark tint */
    color:#fff            !important;             /* white text     */
    border-bottom:1px solid #1de9b6 !important;   /* your teal line */
    box-shadow:0 1px 0 0 #1de9b6   !important;
}

/* optional – keep the label teal when the field is active */
.input-field input[type=search]:focus + label {
    color:#1de9b6 !important;
}

/* make the little × (clear button) visible in dark mode */
.input-field input[type=search]::-webkit-search-cancel-button {
    filter:invert(1);
}
    </style>
</head>
<body>
<?php include_once __DIR__.'/includes/header.php'; ?>
<hr class="white-line-header">

<main>
<div class="container">
    <center><h1 class="site-title">hospital0</h1></center>
    <div class="center-heading-container"><center><h5 class="lab-title">Laboratory Management System</h5></center></div>

    <div class="row">
        <div class="col s12">
            <ul class="tabs tabs-fixed-width">
                <li class="tab col s3"><a href="#view_all_tests" class="<?= $active_tab === 'view_all_tests' ? 'active' : '' ?>">View All Tests</a></li>
                <li class="tab col s3"><a href="#manage_tests"   class="<?= $active_tab === 'manage_tests'   ? 'active' : '' ?>">Manage Tests</a></li>
            </ul>
        </div>
    </div>

    <!-- Flash‑message banners -->
    <div id="message-banner-area">
        <?php if ($error_message): ?>
        <div class="banner banner-err"><i class="material-icons">error_outline</i><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
        <div class="banner banner-ok"><i class="material-icons">check_circle_outline</i><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
    </div>

    <div class="col s12" style="margin-top: 20px;">
        <!-- --------------------------- View All Tests -------------------- -->
        <div id="view_all_tests" style="display: <?= $active_tab === 'view_all_tests' ? 'block' : 'none' ?>;">
            <h4 class="tab-title"><center>Available Laboratory Tests</center></h4>
            <?php if ($all_tests): ?>
            <table class="striped highlight responsive-table">
                <thead>
                    <tr><th>Test Name</th><th>Description</th><th>Price (PKR)</th></tr>
                </thead>
                <tbody>
                <?php foreach ($all_tests as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['test_name']) ?></td>
                        <td><?= nl2br(htmlspecialchars($t['test_description'] ?: 'N/A')) ?></td>
                        <td><?= number_format($t['test_price'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="center-align white-text">No active tests found.</p>
            <?php endif; ?>
        </div>

        <!-- --------------------------- Manage Tests ---------------------- -->
        <div id="manage_tests" style="display: <?= $active_tab === 'manage_tests' ? 'block' : 'none' ?>;">
            <h4 class="tab-title"><center>Manage Laboratory Tests</center></h4>

            <h5 class="sub-title">Add New Test</h5>
            <form method="POST" action="labs.php?tab=manage_tests" id="addTestForm">
                <input type="hidden" name="action" value="add_test">

                <div class="row">
                    <div class="input-field col s12 m8 l5">
                        <input id="test_name" name="test_name" type="text" required>
                        <label for="test_name">Test Name*</label>
                    </div>
                    <div class="input-field col s12 m4 l3">
                        <input id="test_price" name="test_price" type="number" step="0.01" min="0" required>
                        <label for="test_price">Price (PKR)*</label>
                    </div>
                </div>

                <div class="row">
                    <div class="input-field col s12">
                        <textarea id="test_description" name="test_description" class="materialize-textarea"></textarea>
                        <label for="test_description">Test Description</label>
                    </div>
                </div>

                <!-- Optional parameters -->
                <div id="parameters-section" style="display:none;">
                    <h5 class="sub-title" style="margin-top:30px;">Test Parameters</h5>
                    <div id="parameters-container">
                        <div class="parameter-set">
                            <div class="row">
                                <div class="input-field col s12 m5 l5">
                                    <input type="text" name="parameter_name[]" id="parameter_name_1">
                                    <label for="parameter_name_1">Parameter Name (e.g., Hb)</label>
                                </div>
                                <div class="input-field col s12 m4 l4">
                                    <input type="text" name="reference_range[]" id="reference_range_1" required>
                                    <label for="reference_range_1">Reference Range*</label>
                                </div>
                                <div class="input-field col s12 m3 l3">
                                    <input type="text" name="result_unit[]" id="result_unit_1" required>
                                    <label for="result_unit_1">Unit*</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col s12">
                            <button type="button" class="btn-small grey darken-1 waves-effect waves-light" id="addParameterBtn">
                                <i class="material-icons left">add</i>Add Parameter
                            </button>
                        </div>
                    </div>
                </div>

                <div class="row center-align" style="margin-top:30px;">
                    <button class="btn waves-effect waves-light" type="submit">
                        <i class="material-icons right">save</i>Save Test
                    </button>
                </div>
            </form>

            <hr style="border-color:rgba(255,255,255,0.2); margin:40px 0;">

            <h5 class="sub-title">Existing Tests</h5>
            <div class="search-wrapper row">
                <div class="input-field col s12 m6 l4">
                    <i class="material-icons prefix">search</i>
                    <input type="search" id="manageTestSearchInput" placeholder="Search tests by name...">
                    <label for="manageTestSearchInput">Search Tests</label>
                </div>
            </div>

            <?php if ($manage_tests_list): ?>
            <table class="striped highlight responsive-table" id="manageTestTable">
                <thead>
                    <tr><th>Test Name</th><th>Price (PKR)</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($manage_tests_list as $t): ?>
                    <tr data-test-name="<?= strtolower(htmlspecialchars($t['test_name'])) ?>">
                        <td><?= htmlspecialchars($t['test_name']) ?></td>
                        <td><?= number_format($t['test_price'], 2) ?></td>
                        <td>
                            <span class="new badge <?= $t['is_active'] ? 'green' : 'red' ?>" data-badge-caption="">
                                <?= $t['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap;">
                            <!-- Toggle status -->
                            <form method="POST" action="labs.php?tab=manage_tests" style="display:inline-block; margin-right:5px;">
                                <input type="hidden" name="action" value="toggle_test_status">
                                <input type="hidden" name="test_id" value="<?= $t['test_id'] ?>">
                                <input type="hidden" name="current_status" value="<?= $t['is_active'] ?>">
                                <button class="btn-small waves-effect waves-light tooltipped <?= $t['is_active'] ? 'red' : 'green' ?>" data-position="top" data-tooltip="<?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>" type="submit">
                                    <i class="material-icons"><?= $t['is_active'] ? 'visibility_off' : 'visibility' ?></i>
                                </button>
                            </form>
                            <!-- Delete test -->
                            <form method="POST" action="labs.php?tab=manage_tests" style="display:inline-block;" onsubmit="return confirm('Delete the test \"<?= addslashes($t['test_name']) ?>\" permanently?');">
                                <input type="hidden" name="action" value="delete_test">
                                <input type="hidden" name="test_id" value="<?= $t['test_id'] ?>">
                                <button class="btn-small red darken-2 waves-effect waves-light tooltipped" data-position="top" data-tooltip="Delete Test Permanently" type="submit">
                                    <i class="material-icons">delete_forever</i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="center-align white-text">No tests found.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
</main>

<?php include_once __DIR__.'/includes/footer.php'; ?>

<!-- --------------------------------------------------------------------- -->
<!--  Scripts                                                              -->
<!-- --------------------------------------------------------------------- -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Materialize initialisation
        try {
            var selects = document.querySelectorAll('select');
            if (selects.length) M.FormSelect.init(selects);

            var tooltips = document.querySelectorAll('.tooltipped');
            if (tooltips.length) M.Tooltip.init(tooltips);

            var tabsEl = document.querySelector('.tabs');
            if (tabsEl) {
                M.Tabs.init(tabsEl, {
                    onShow: function() {
                        const bannerArea = document.getElementById('message-banner-area');
                        if (bannerArea) bannerArea.querySelectorAll('.banner').forEach(b => b.remove());
                        M.updateTextFields();
                    }
                });
            }
        } catch (e) {
            console.error('Materialize init error:', e);
        }

        // Manage‑Tests: show/hide parameters section
        const testNameInput      = document.getElementById('test_name');
        const parametersSection  = document.getElementById('parameters-section');
        const addParameterBtn    = document.getElementById('addParameterBtn');
        const parametersContainer= document.getElementById('parameters-container');
        let parameterIndex = 1;

        if (testNameInput && parametersSection) {
            const toggleParameters = () => {
                parametersSection.style.display = testNameInput.value.trim() ? 'block' : 'none';
            };
            testNameInput.addEventListener('input', toggleParameters);
            toggleParameters(); // initial
        }

        // Add / remove parameter rows
        if (addParameterBtn && parametersContainer) {
            addParameterBtn.addEventListener('click', () => {
                parameterIndex++;
                const set = document.createElement('div');
                set.className = 'parameter-set';
                set.innerHTML = `
                    <div class="row">
                        <div class="input-field col s12 m5 l5">
                            <input type="text" name="parameter_name[]" id="parameter_name_${parameterIndex}">
                            <label for="parameter_name_${parameterIndex}">Parameter Name</label>
                        </div>
                        <div class="input-field col s12 m4 l4">
                            <input type="text" name="reference_range[]" id="reference_range_${parameterIndex}" required>
                            <label for="reference_range_${parameterIndex}">Reference Range*</label>
                        </div>
                        <div class="input-field col s12 m3 l3">
                            <input type="text" name="result_unit[]" id="result_unit_${parameterIndex}" required>
                            <label for="result_unit_${parameterIndex}">Unit*</label>
                        </div>
                    </div>
                    <button type="button" class="btn-small red removeParameterBtn tooltipped" data-position="top" data-tooltip="Remove Parameter">
                        <i class="material-icons">remove_circle_outline</i>
                    </button>`;
                parametersContainer.appendChild(set);
                var newTips = set.querySelectorAll('.tooltipped');
                if (newTips.length) M.Tooltip.init(newTips);
                M.updateTextFields();
            });
        }
        if (parametersContainer) {
            parametersContainer.addEventListener('click', function(e) {
                const btn = e.target.closest('.removeParameterBtn');
                if (btn) {
                    const tip = M.Tooltip.getInstance(btn);
                    if (tip) tip.destroy();
                    btn.parentElement.remove();
                }
            });
        }

        // Manage‑Tests table search
        const searchInput = document.getElementById('manageTestSearchInput');
        const tableBody   = document.querySelector('#manageTestTable tbody');
        if (searchInput && tableBody) {
            searchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase().trim();
                tableBody.querySelectorAll('tr').forEach(tr => {
                    const name = tr.dataset.testName || '';
                    tr.style.display = name.includes(term) ? '' : 'none';
                });
            });
        }

        M.updateTextFields(); // ensure labels float correctly
    });
</script>
</body>
</html>
