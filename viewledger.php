<?php
// /public/viewledger.php – Modernized General Ledger

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/config.php';

// --- User Permissions ---
$user = $_SESSION['user'] ?? [];
$allowedGroups = [1, 2, 3, 5, 8, 21, 23]; // CMO, Aesth, Dent, Ops, Recep, Stake, GM
$no_rights = !in_array(($user['group_id'] ?? 0), $allowedGroups, true);
$is_admin = (($user['group_id'] ?? 0) === 1); // Check if user is an admin (group_id 1)

$feedback_message = '';
$feedback_type = '';

// --- Handle New Entry Form Submission ---
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_entry'])) {
    $description = trim($_POST['description'] ?? '');
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $type = $_POST['type'] ?? '';

    if (empty($description) || $amount === false || $amount <= 0 || !in_array($type, ['credit', 'debit'])) {
        $feedback_message = 'Invalid input. Please fill all fields correctly.';
        $feedback_type = 'error';
    } else {
        try {
            $finalAmount = ($type === 'debit') ? -abs($amount) : abs($amount);
            
            $insertStmt = $pdo->prepare(
                "INSERT INTO generalledger (datetime, description, amount, user) VALUES (NOW(), :description, :amount, :user)"
            );
            $insertStmt->execute([
                ':description' => $description,
                ':amount' => $finalAmount,
                ':user' => $user['username'] ?? 'admin'
            ]);
            
            // Redirect to avoid form resubmission
            header("Location: viewledger.php?entry_added=1");
            exit;

        } catch (PDOException $e) {
            error_log("Failed to add ledger entry: " . $e->getMessage());
            $feedback_message = 'Failed to add entry. A database error occurred.';
            $feedback_type = 'error';
        }
    }
}

// Handle success message on redirect
if (isset($_GET['entry_added']) && $_GET['entry_added'] == '1') {
    $feedback_message = 'New ledger entry added successfully.';
    $feedback_type = 'success';
}


// --- Date Filtering & Search Logic ---
$perPage = 15;
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $perPage;

$whereParts = [];
$params = [];

// Date range for table and summary stats
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$whereParts[] = "datetime BETWEEN :start_date AND :end_date";
$params[':start_date'] = $startDate . ' 00:00:00';
$params[':end_date'] = $endDate . ' 23:59:59';

// Search filter
$searchTerm = trim($_GET['search'] ?? '');
if ($searchTerm !== '') {
    $whereParts[] = "(description LIKE :search OR user LIKE :search OR CAST(amount AS CHAR) LIKE :search)";
    $params[':search'] = "%$searchTerm%";
}

$whereSQL = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// --- Data Fetching ---
$entries = [];
$totalRevenue = 0;
$totalExpenses = 0;
$netProfit = 0;
$totalEntries = 0;
$monthlyChartData = [];
$forecastData = [];

if (!$no_rights) {
    try {
        // Fetch total count for pagination
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM generalledger $whereSQL");
        $countStmt->execute($params);
        $totalEntries = (int)$countStmt->fetchColumn();

        // Fetch paginated ledger entries for the table
        $stmt = $pdo->prepare("SELECT * FROM generalledger $whereSQL ORDER BY datetime DESC LIMIT :limit OFFSET :offset");
        
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        
        $stmt->execute();
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate summary stats for the selected period
        $summaryStmt = $pdo->prepare("SELECT SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_revenue, SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_expenses FROM generalledger $whereSQL");
        $summaryStmt->execute($params);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        $totalRevenue = (float)($summary['total_revenue'] ?? 0);
        $totalExpenses = (float)($summary['total_expenses'] ?? 0);
        $netProfit = $totalRevenue - $totalExpenses;
        
        // --- CHART DATA: Monthly Revenue & Expenses (Last 12 Months) ---
        $monthlyStmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(datetime, '%Y-%m') as month,
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as revenue,
                SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as expense
            FROM generalledger
            WHERE datetime >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY month
            ORDER BY month ASC
        ");
        $monthlyStmt->execute();
        $monthlyChartData = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

        // --- CHART DATA: Current Month Forecast ---
        $currentMonthStart = date('Y-m-01 00:00:00');
        $currentDate = date('Y-m-d H:i:s');
        $daysInMonth = (int)date('t');
        $currentDayOfMonth = (int)date('j');

        $forecastStmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as current_revenue,
                SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as current_expense
            FROM generalledger
            WHERE datetime BETWEEN :start AND :end
        ");
        $forecastStmt->execute([':start' => $currentMonthStart, ':end' => $currentDate]);
        $currentTotals = $forecastStmt->fetch(PDO::FETCH_ASSOC);
        
        $currentRevenue = (float)($currentTotals['current_revenue'] ?? 0);
        $currentExpense = (float)($currentTotals['current_expense'] ?? 0);

        $dailyAvgRevenue = ($currentDayOfMonth > 0) ? ($currentRevenue / $currentDayOfMonth) : 0;
        $dailyAvgExpense = ($currentDayOfMonth > 0) ? ($currentExpense / $currentDayOfMonth) : 0;

        $forecastData = [
            'projectedRevenue' => $dailyAvgRevenue * $daysInMonth,
            'projectedExpense' => $dailyAvgExpense * $daysInMonth,
            'currentRevenue' => $currentRevenue,
            'currentExpense' => $currentExpense,
            'daysInMonth' => $daysInMonth,
            'currentDayOfMonth' => $currentDayOfMonth
        ];

    } catch (PDOException $e) {
        error_log("Database Error in viewledger.php: " . $e->getMessage());
        die("A database error occurred. Please try again later or contact support.");
    } catch (Throwable $e) {
        error_log("General Error in viewledger.php: " . $e->getMessage());
        die("An unexpected error occurred. Please contact support.");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - General Ledger - hospital0</title>
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <link rel="icon" href="/media/sitelogo.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-image: none !important; background-color: #121212 !important; color: #fff; overflow-x: hidden; }
        @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
        .stars, .twinkling { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; display: block; z-index: -3; }
        .stars { background: #000 url(/media/stars.png) repeat top center; }
        .twinkling { background: transparent url(/media/twinkling.png) repeat top center; animation: move-twink-back 200s linear infinite; }
        #dna-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; opacity: 0.3; }
        h3.center-align, h5.white-text { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
        .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
        .container { max-width: 1600px; width: 95%; }
        
        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 2rem; margin-top: 1.5rem; }
        
        .stat-box { background: rgba(0, 0, 0, 0.2); padding: 20px; border-radius: 10px; text-align: center; color: white; margin-bottom: 1rem; border: 1px solid rgba(255, 255, 255, 0.1); height: 100%; }
        .stat-box h5 { margin: 0 0 10px 0; font-size: 1.1rem; color: #bdbdbd; text-transform: uppercase; }
        .stat-box p { margin: 0; font-size: 2.2rem; font-weight: bold; }
        p.green-text { color: #81c784 !important; } p.red-text { color: #e57373 !important; }
        
        .input-field input, .input-field .select-dropdown { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }
        .input-field label { color: #bdbdbd !important; } .input-field label.active { color: #00e5ff !important; }
        .input-field input:focus, .input-field .select-wrapper input.select-dropdown:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
        ul.dropdown-content { background-color: #2a2a2a; } .dropdown-content li>span { color: #fff !important; }
        
        table.striped>tbody>tr:nth-child(odd) { background-color: rgba(255, 255, 255, 0.05); }
        th { border-bottom: 1px solid rgba(255, 255, 255, 0.3); } td, th { padding: 15px 10px; }
        .negative-amount { color:#e57373; font-weight:bold; } .positive-amount { color:#81c784; font-weight:bold; }
        
        .pagination li a { color: #fff; } .pagination li.active { background-color: #00bfa5; } .pagination li.disabled a { color: #757575; }

        .chart-container { position: relative; height: 380px; width: 100%; }

        .message-area { padding: 10px 15px; margin-top: 20px; border-radius: 8px; text-align: center; border: 1px solid; }
        .message-area.success { background-color: rgba(76, 175, 80, 0.25); color: #c8e6c9; border-color: rgba(129, 199, 132, 0.5); }
        .message-area.error { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border-color: rgba(239, 154, 154, 0.5); }

        /* --- New Sophisticated Forecast Styles --- */
        .forecast-gauge-container { display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .forecast-gauge { position: relative; width: 180px; height: 180px; }
        .forecast-gauge svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .forecast-gauge-bg { fill: none; stroke: rgba(255, 255, 255, 0.1); stroke-width: 12; }
        .forecast-gauge-fg { fill: none; stroke-width: 12; stroke-linecap: round; transition: stroke-dashoffset 1.5s ease-out; animation: progress-animation 2s ease-out forwards; }
        .forecast-gauge-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
        .forecast-gauge-text h5 { margin: 0; font-weight: 600; font-size: 2.2rem; line-height: 1; text-shadow: 0 0 5px rgba(0,0,0,0.5); }
        .forecast-gauge-text p { margin: 0; font-size: 0.9rem; color: #bdbdbd; }
        .forecast-details { text-align: center; margin-top: 1.5rem; }
        .forecast-details h6 { margin: 0; font-size: 1.3rem; font-weight: 500; }
        .forecast-details p { margin: 4px 0 0 0; color: #bdbdbd; font-size: 0.9rem; }
        .forecast-details .projected-amount { font-size: 1rem; color: #fff; font-weight: 500; margin-top: 8px; }
        .divider-vertical { border-left: 1px solid rgba(255, 255, 255, 0.15); min-height: 200px; }
        @keyframes progress-animation { from { stroke-dashoffset: 528; } }
        @media (max-width: 992px) { .divider-vertical { border-left: none; border-top: 1px solid rgba(255, 255, 255, 0.15); min-height: auto; margin: 2rem 0; width: 80%; } }
    </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
<?php if($no_rights): ?>
  <div class="glass-card center-align" style="margin-top: 5rem;"><h5 class="red-text text-lighten-2">You do not have rights to view this page.</h5></div>
<?php else: ?>
  <h3 class="center-align white-text" style="margin-top:30px;">The General Ledger</h3>
  <hr class="white-line">
  
  <?php if (!empty($feedback_message)): ?>
    <div class="message-area <?= $feedback_type === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($feedback_message); ?></div>
  <?php endif; ?>

  <div class="glass-card">
    <form method="get">
        <div class="row" style="align-items: flex-end; margin-bottom:0;">
            <div class="input-field col s12 l3"><i class="material-icons prefix">date_range</i><input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>"><label for="start_date">Start Date</label></div>
            <div class="input-field col s12 l3"><i class="material-icons prefix">date_range</i><input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>"><label for="end_date">End Date</label></div>
            <div class="input-field col s12 l4"><i class="material-icons prefix">search</i><input type="text" id="search" name="search" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Search description, user, amount..."><label for="search">Search</label></div>
            <div class="input-field col s12 l2"><button type="submit" class="btn waves-effect waves-light" style="width:100%; background-color:#00bfa5;"><i class="material-icons">filter_alt</i></button></div>
        </div>
    </form>
  </div>
  
  <div class="row" style="margin-top: 2rem;">
    <div class="col s12 m4"><div class="stat-box"><h5>Total Revenue</h5><p class="green-text text-lighten-2">PKR <?= number_format($totalRevenue, 2) ?></p></div></div>
    <div class="col s12 m4"><div class="stat-box"><h5>Total Expenses</h5><p class="red-text text-lighten-2">PKR <?= number_format($totalExpenses, 2) ?></p></div></div>
    <div class="col s12 m4"><div class="stat-box"><h5>Net Profit</h5><p class="<?= $netProfit >= 0 ? 'green-text' : 'red-text'; ?> text-lighten-2">PKR <?= number_format($netProfit, 2) ?></p></div></div>
  </div>

  <div class="row">
      <div class="col s12 l6">
          <div class="glass-card">
              <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Monthly Revenue Trend</h5>
              <div class="chart-container">
                  <canvas id="monthlyRevenueChart"></canvas>
              </div>
          </div>
      </div>
       <div class="col s12 l6">
          <div class="glass-card">
              <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Monthly Expense Trend</h5>
              <div class="chart-container">
                  <canvas id="monthlyExpenseChart"></canvas>
              </div>
          </div>
      </div>
  </div>
  
    <?php
        $revenuePercent = $forecastData['projectedRevenue'] > 0 ? ($forecastData['currentRevenue'] / $forecastData['projectedRevenue']) * 100 : 0;
        $expensePercent = $forecastData['projectedExpense'] > 0 ? ($forecastData['currentExpense'] / $forecastData['projectedExpense']) * 100 : 0;
        $revenuePercentCapped = min($revenuePercent, 100);
        $expensePercentCapped = min($expensePercent, 100);
        
        $circumference = 2 * M_PI * 84; // r=84 for the SVG circle
        $revStrokeOffset = $circumference * (1 - ($revenuePercentCapped / 100));
        $expStrokeOffset = $circumference * (1 - ($expensePercentCapped / 100));
    ?>
    <div class="glass-card forecast-card">
        <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Smart Forecast: <?= date('F Y') ?></h5>
        <p class="grey-text text-lighten-1">Projections based on performance up to Day <?= $forecastData['currentDayOfMonth'] ?> of <?= $forecastData['daysInMonth'] ?>.</p>
        <div class="row" style="margin-top:2rem; align-items: center;">
            <div class="col s12 m5">
                <div class="forecast-gauge-container">
                    <div class="forecast-gauge">
                        <svg viewBox="0 0 180 180">
                            <circle class="forecast-gauge-bg" cx="90" cy="90" r="84"></circle>
                            <circle class="forecast-gauge-fg" cx="90" cy="90" r="84" stroke="#81c784" stroke-dasharray="<?= $circumference ?>" style="stroke-dashoffset: <?= $revStrokeOffset ?>;"></circle>
                        </svg>
                        <div class="forecast-gauge-text">
                            <h5 class="green-text text-lighten-2"><?= number_format($revenuePercent, 0) ?>%</h5>
                            <p>Revenue</p>
                        </div>
                    </div>
                    <div class="forecast-details">
                        <h6>PKR <?= number_format($forecastData['currentRevenue'], 0) ?></h6>
                        <p>Current Revenue</p>
                        <p class="projected-amount">Projected: PKR <?= number_format($forecastData['projectedRevenue'], 0) ?></p>
                    </div>
                </div>
            </div>
            <div class="col m2 center-align hide-on-small-only">
                <div class="divider-vertical"></div>
            </div>
             <div class="col s12 show-on-small hide-on-med-and-up center-align">
                <div class="divider-vertical"></div>
            </div>
            <div class="col s12 m5">
                 <div class="forecast-gauge-container">
                    <div class="forecast-gauge">
                        <svg viewBox="0 0 180 180">
                            <circle class="forecast-gauge-bg" cx="90" cy="90" r="84"></circle>
                            <circle class="forecast-gauge-fg" cx="90" cy="90" r="84" stroke="#e57373" stroke-dasharray="<?= $circumference ?>" style="stroke-dashoffset: <?= $expStrokeOffset ?>;"></circle>
                        </svg>
                         <div class="forecast-gauge-text">
                            <h5 class="red-text text-lighten-2"><?= number_format($expensePercent, 0) ?>%</h5>
                            <p>Expenses</p>
                        </div>
                    </div>
                     <div class="forecast-details">
                        <h6>PKR <?= number_format($forecastData['currentExpense'], 0) ?></h6>
                        <p>Current Expenses</p>
                        <p class="projected-amount">Projected: PKR <?= number_format($forecastData['projectedExpense'], 0) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <div class="glass-card">
        <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);"><i class="material-icons left" style="vertical-align: middle;">add_circle_outline</i>Add New Ledger Entry</h5>
        <form method="POST" action="viewledger.php">
            <div class="row" style="margin-bottom: 0;">
                <div class="input-field col s12 l6">
                    <i class="material-icons prefix">description</i>
                    <input id="description" name="description" type="text" class="validate" required>
                    <label for="description">Description</label>
                </div>
                <div class="input-field col s6 l3">
                    <i class="material-icons prefix">attach_money</i>
                    <input id="amount" name="amount" type="number" step="0.01" min="0.01" class="validate" required>
                    <label for="amount">Amount (PKR)</label>
                </div>
                <div class="input-field col s6 l3">
                    <select name="type" required>
                        <option value="" disabled selected>Choose Type</option>
                        <option value="credit">Credit (Revenue)</option>
                        <option value="debit">Debit (Expense)</option>
                    </select>
                    <label>Entry Type</label>
                </div>
            </div>
             <div class="row">
                <div class="col s12 center-align">
                    <button type="submit" name="add_entry" class="btn-large waves-effect waves-light" style="background-color:#00bfa5;"><i class="material-icons left">add</i>Add Entry</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

  <div class="glass-card">
    <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Ledger Entries</h5>
    <table class="striped highlight responsive-table">
        <thead><tr><th>Date & Time</th><th>Description</th><th>Debit (Expense)</th><th>Credit (Revenue)</th><th>User</th></tr></thead>
        <tbody>
            <?php if (empty($entries)): ?>
                <tr><td colspan="5" class="center-align">No entries found for the selected period.</td></tr>
            <?php else: foreach ($entries as $entry): ?>
                <tr>
                    <td><?= htmlspecialchars(date('d-M-Y H:i', strtotime($entry['datetime']))) ?></td>
                    <td><?= htmlspecialchars($entry['description']) ?></td>
                    <td class="negative-amount"><?= $entry['amount'] < 0 ? 'PKR ' . number_format(abs($entry['amount']), 2) : '-' ?></td>
                    <td class="positive-amount"><?= $entry['amount'] > 0 ? 'PKR ' . number_format($entry['amount'], 2) : '-' ?></td>
                    <td><?= htmlspecialchars($entry['user']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <ul class="pagination center">
        <?php
            $totalPages = ceil($totalEntries / $perPage);
            if ($totalPages > 1) {
                $queryParams = http_build_query(['start_date' => $startDate, 'end_date' => $endDate, 'search' => $searchTerm]);
                echo '<li class="' . ($currentPage <= 1 ? 'disabled' : 'waves-effect') . '"><a href="?page=' . ($currentPage - 1) . '&' . $queryParams . '"><i class="material-icons">chevron_left</i></a></li>';
                for ($i = 1; $i <= $totalPages; $i++) {
                    echo '<li class="' . ($i == $currentPage ? 'active' : 'waves-effect') . '"><a href="?page=' . $i . '&' . $queryParams . '">' . $i . '</a></li>';
                }
                echo '<li class="' . ($currentPage >= $totalPages ? 'disabled' : 'waves-effect') . '"><a href="?page=' . ($currentPage + 1) . '&' . $queryParams . '"><i class="material-icons">chevron_right</i></a></li>';
            }
        ?>
    </ul>
  </div>

<?php endif; ?>
</main>
<br>

<?php include_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script type="module">
    // 3D background animation
    import * as THREE from 'https://cdn.jsdelivr.net/npm/three@0.164.1/build/three.module.js';
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
document.addEventListener('DOMContentLoaded', function() {
    M.AutoInit();
    Chart.defaults.color = '#bdbdbd';

    const monthlyData = <?= json_encode($monthlyChartData) ?>;
    const lineChartLabels = monthlyData.map(item => new Date(item.month + '-01T00:00:00').toLocaleDateString('en-US', { month: 'short', year: '2-digit' }));
    const revenueData = monthlyData.map(item => item.revenue);
    const expenseData = monthlyData.map(item => item.expense);

    const lineChartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                ticks: { color: '#bdbdbd', callback: value => 'PKR ' + (value / 1000) + 'k' },
                grid: { color: 'rgba(255, 255, 255, 0.1)' }
            },
            x: {
                ticks: { color: '#bdbdbd' },
                grid: { color: 'rgba(255, 255, 255, 0.1)' }
            }
        },
        plugins: {
            legend: { labels: { color: '#ffffff' } },
            tooltip: {
                backgroundColor: 'rgba(0,0,0,0.8)', titleColor: '#ffffff', bodyColor: '#ffffff',
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) { label += ': '; }
                        if (context.parsed.y !== null) {
                            label += new Intl.NumberFormat('en-US', { style: 'currency', currency: 'PKR' }).format(context.parsed.y);
                        }
                        return label;
                    }
                }
            }
        },
        interaction: { intersect: false, mode: 'index' },
    };
    
    // --- Monthly Revenue Line Chart ---
    const revCtx = document.getElementById('monthlyRevenueChart')?.getContext('2d');
    if (revCtx) {
        new Chart(revCtx, {
            type: 'line',
            data: {
                labels: lineChartLabels,
                datasets: [{
                    label: 'Revenue',
                    data: revenueData,
                    borderColor: '#00e5ff', 
                    backgroundColor: 'rgba(0, 229, 255, 0.1)',
                    pointBackgroundColor: '#00e5ff',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: lineChartOptions
        });
    }

    // --- Monthly Expense Line Chart ---
    const expCtx = document.getElementById('monthlyExpenseChart')?.getContext('2d');
    if (expCtx) {
         new Chart(expCtx, {
            type: 'line',
            data: {
                labels: lineChartLabels,
                datasets: [{
                    label: 'Expenses',
                    data: expenseData,
                    borderColor: '#ff8a65',
                    backgroundColor: 'rgba(255, 138, 101, 0.1)',
                    pointBackgroundColor: '#ff8a65',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: lineChartOptions
        });
    }
});
</script>
</body>
</html>