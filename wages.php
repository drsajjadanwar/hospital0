<?php
// /public/wages.php
// Enhanced by Gemini (10-Nov-2025)
// v6: Replaced green 'Paid' chip with normal text and enhanced pie chart to a modern donut chart.

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
// **Permissions check for group_id 1 (CMO) and 23 (General Manager)**
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['group_id'] ?? 0, [1, 23], true)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied. You do not have permission to view this page.');
}

require_once __DIR__ . '/includes/config.php';
date_default_timezone_set('Asia/Karachi');

// --- HELPER FUNCTIONS ---
function getWorkingDays(int $year, int $month): int {
    $totalDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $workingDays = 0;
    for ($day = 1; $day <= $totalDays; $day++) {
        $weekday = date('N', strtotime("$year-$month-$day"));
        if ($weekday < 7) { // Count Monday (1) to Saturday (6)
            $workingDays++;
        }
    }
    return $workingDays;
}

/**
 * Calculates a simple linear regression forecast for the next period.
 * y = mx + b
 */
function calculate_linear_regression(array $data): float {
    $n = count($data);
    if ($n < 2) {
        return $data[0] ?? 0.0; // Not enough data, return last value or 0
    }

    $sum_x = 0;
    $sum_y = 0;
    $sum_xy = 0;
    $sum_x_squared = 0;

    // $x is 1-based index (1, 2, 3...), $y is the value
    foreach ($data as $index => $y) {
        $x = $index + 1;
        $y = (float)$y;
        $sum_x += $x;
        $sum_y += $y;
        $sum_xy += ($x * $y);
        $sum_x_squared += ($x * $x);
    }

    // Calculate slope (m)
    $m_numerator = ($n * $sum_xy) - ($sum_x * $sum_y);
    $m_denominator = ($n * $sum_x_squared) - ($sum_x * $sum_x);
    $m = ($m_denominator == 0) ? 0 : $m_numerator / $m_denominator;

    // Calculate y-intercept (b)
    $b = ($sum_y - $m * $sum_x) / $n;

    // Predict the value for the next period (x = n + 1)
    $forecast = ($m * ($n + 1)) + $b;

    // Ensure forecast isn't negative
    return max(0, round($forecast, 2));
}

// --- DATE & MONTH SELECTION ---
$selectedYearMonth = $_GET['month'] ?? date('Y-m');
list($selectedYear, $selectedMonth) = array_map('intval', explode('-', $selectedYearMonth));
$pageTitle = "Wages for " . date('F Y', strtotime($selectedYearMonth . '-01'));

// 🌟 TIME LIMIT LOGIC (10-Nov-2025) 🌟
// **Disbursement is allowed only for the current and previous month**
$today = new DateTime('now', new DateTimeZone('Asia/Karachi'));
$currentYearMonth = $today->format('Y-m');
$previousYearMonth = (new DateTime('now', new DateTimeZone('Asia/Karachi')))->modify('-1 month')->format('Y-m');

$canDisburse = in_array($selectedYearMonth, [$currentYearMonth, $previousYearMonth]);


// Generate dropdown for the last 12 months
$last12Months = [];
for ($i = 0; $i < 12; $i++) {
    $ts = strtotime("first day of -$i month");
    $ym = date('Y-m', $ts);
    $last12Months[$ym] = date('F Y', $ts);
}

// --- POST ACTION: DISBURSEMENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disburse_salary'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    $disbursedAmount = (float)($_POST['disbursed_amount'] ?? 0.0);
    $notes = trim($_POST['notes'] ?? '');

    // Read 'total_hours' and 'calculated_amount' from monthly_wages_summary
    $stmtCalc = $pdo->prepare("SELECT total_hours, calculated_amount FROM monthly_wages_summary WHERE user_id=? AND year=? AND month=?");
    $stmtCalc->execute([$userId, $selectedYear, $selectedMonth]);
    $summaryData = $stmtCalc->fetch(PDO::FETCH_ASSOC);

    if ($userId > 0 && $summaryData) {
        // The INSERT statement maps to the columns in 'wages_disbursements'
        $stmt = $pdo->prepare("
            INSERT INTO wages_disbursements (user_id, year, month, hours_worked, calculated_pay, disbursed_amount, notes, status, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', NULL)
            ON DUPLICATE KEY UPDATE
                hours_worked = VALUES(hours_worked),
                calculated_pay = VALUES(calculated_pay),
                disbursed_amount = VALUES(disbursed_amount),
                notes = VALUES(notes),
                status = VALUES(status)
        ");
        
        // Pass the correct fetched variables to the execute() array
        $stmt->execute([
            $userId, 
            $selectedYear, 
            $selectedMonth, 
            $summaryData['total_hours'],         // This value goes into the 'hours_worked' column
            $summaryData['calculated_amount'],   // This value goes into the 'calculated_pay' column
            $disbursedAmount, 
            ($notes ?: null)
        ]);
    }
    header("Location: wages.php?month=" . $selectedYearMonth);
    exit;
}

// --- DATA FETCHING & CALCULATION (FOR MAIN TABLE) ---
$activeUsers = $pdo->query("
    SELECT u.user_id, u.full_name, u.group_id, u.monthly_pay, u.hours_per_week, g.group_name
    FROM users u
    LEFT JOIN `groups` g ON g.group_id = u.group_id
    WHERE u.group_id != 21 AND u.terminated = 0 AND (u.suspended_until IS NULL OR u.suspended_until <= NOW())
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

list($monthStart, $monthEnd) = [date('Y-m-01 00:00:00', strtotime($selectedYearMonth . '-01')), date('Y-m-01 00:00:00', strtotime($selectedYearMonth . '-01' . ' +1 month'))];

$hoursWorkedStmt = $pdo->prepare("
    SELECT user_id, SUM(TIMESTAMPDIFF(SECOND, check_in, check_out)) / 3600 AS hrs
    FROM attendance_logs
    WHERE check_in >= ? AND check_in < ? AND check_out IS NOT NULL
    GROUP BY user_id
");
$hoursWorkedStmt->execute([$monthStart, $monthEnd]);
$hoursWorkedMap = $hoursWorkedStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$workingDaysInMonth = getWorkingDays($selectedYear, $selectedMonth);

$wageData = [];
$totalCalculated = 0;
$totalDisbursed = 0;
$totalHoursLogged = 0;

// **CRITICAL: Ensure summary table is up-to-date for the selected month**
$pdo->prepare("DELETE FROM monthly_wages_summary WHERE year = ? AND month = ?")->execute([$selectedYear, $selectedMonth]);
$summaryInsertStmt = $pdo->prepare("INSERT INTO monthly_wages_summary (user_id, year, month, total_hours, hourly_rate, calculated_amount) VALUES (?, ?, ?, ?, ?, ?)");

foreach ($activeUsers as $user) {
    $userId = $user['user_id'];
    
    $dailyHours = ($user['hours_per_week'] > 0) ? $user['hours_per_week'] / 6 : 0;
    $expectedHoursInMonth = $dailyHours * $workingDaysInMonth;
    
    $actualHours = round($hoursWorkedMap[$userId] ?? 0, 2);
    $hourlyRate = ($expectedHoursInMonth > 0 && $user['monthly_pay'] > 0) ? ($user['monthly_pay'] / $expectedHoursInMonth) : 0;
    $calculatedPay = round($actualHours * $hourlyRate, 2);

    $summaryInsertStmt->execute([$userId, $selectedYear, $selectedMonth, $actualHours, $hourlyRate, $calculatedPay]);
    
    $wageData[$userId] = [
        'full_name' => $user['full_name'],
        'group_name' => $user['group_name'],
        'base_salary' => (float)$user['monthly_pay'],
        'expected_hours' => $expectedHoursInMonth,
        'hours_worked' => $actualHours,
        'calculated_pay' => $calculatedPay,
        'disbursed_amount' => null,
        'notes' => null,
        'status' => 'Pending'
    ];
    $totalCalculated += $calculatedPay;
    $totalHoursLogged += $actualHours;
}

// Get disbursement data for the selected month
$disbursedStmt = $pdo->prepare("SELECT user_id, disbursed_amount, notes, status FROM wages_disbursements WHERE year = ? AND month = ?");
$disbursedStmt->execute([$selectedYear, $selectedMonth]);
$disbursedRecords = $disbursedStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($disbursedRecords as $disbursement) {
    $userId = $disbursement['user_id'];
    if (isset($wageData[$userId])) {
        $wageData[$userId]['disbursed_amount'] = (float)$disbursement['disbursed_amount'];
        $wageData[$userId]['notes'] = $disbursement['notes'];
        $wageData[$userId]['status'] = ucfirst($disbursement['status']);
        $totalDisbursed += (float)$disbursement['disbursed_amount'];
    }
}

// **Dashboard Metrics**
$totalUsersProcessed = count($wageData);
$disbursementsMade = count($disbursedRecords);
$disbursementsPending = $totalUsersProcessed - $disbursementsMade;

// --- 🌟 NEW: DATA FETCHING FOR GRAPHS & FORECASTS 🌟 ---

// 1. Historical Data for Line Chart (Last 12 Months)
$historicalDataStmt = $pdo->prepare("
    SELECT
        mws.year,
        mws.month,
        SUM(mws.calculated_amount) AS total_calculated,
        SUM(wd.disbursed_amount) AS total_disbursed
    FROM
        monthly_wages_summary mws
    LEFT JOIN
        wages_disbursements wd ON mws.user_id = wd.user_id AND mws.year = wd.year AND mws.month = wd.month
    WHERE
        STR_TO_DATE(CONCAT(mws.year, '-', mws.month, '-01'), '%Y-%m-%d') >= STR_TO_DATE(CONCAT(:year, '-', :month, '-01'), '%Y-%m-%d') - INTERVAL 11 MONTH
        AND STR_TO_DATE(CONCAT(mws.year, '-', mws.month, '-01'), '%Y-%m-%d') <= STR_TO_DATE(CONCAT(:year, '-', :month, '-01'), '%Y-%m-%d')
    GROUP BY
        mws.year, mws.month
    ORDER BY
        mws.year ASC, mws.month ASC
    LIMIT 12
");
$historicalDataStmt->execute(['year' => $selectedYear, 'month' => $selectedMonth]);
$historicalData = $historicalDataStmt->fetchAll(PDO::FETCH_ASSOC);

// Format for ApexCharts
$chartLabels = [];
$chartCalculated = [];
$chartDisbursed = [];
foreach ($historicalData as $row) {
    $chartLabels[] = date('M Y', strtotime("{$row['year']}-{$row['month']}-01"));
    $chartCalculated[] = round($row['total_calculated'] ?? 0, 2);
    $chartDisbursed[] = round($row['total_disbursed'] ?? 0, 2);
}

// 2. Department Pie Chart Data (Selected Month)
$departmentDataStmt = $pdo->prepare("
    SELECT
        g.group_name,
        SUM(mws.calculated_amount) AS total_pay
    FROM
        monthly_wages_summary mws
    JOIN
        users u ON mws.user_id = u.user_id
    JOIN
        `groups` g ON u.group_id = g.group_id
    WHERE
        mws.year = ? AND mws.month = ? AND mws.calculated_amount > 0
    GROUP BY
        g.group_name
    ORDER BY
        total_pay DESC
");
$departmentDataStmt->execute([$selectedYear, $selectedMonth]);
$departmentData = $departmentDataStmt->fetchAll(PDO::FETCH_ASSOC);

$departmentLabels = [];
$departmentValues = [];
foreach ($departmentData as $row) {
    $departmentLabels[] = $row['group_name'];
    $departmentValues[] = round($row['total_pay'], 2);
}

// 3. Payroll Forecast (Using last 6 months of *calculated* data)
$forecastDataStmt = $pdo->prepare("
    SELECT
        SUM(calculated_amount) AS total_pay
    FROM
        monthly_wages_summary
    WHERE
        STR_TO_DATE(CONCAT(year, '-', month, '-01'), '%Y-%m-%d') < STR_TO_DATE(CONCAT(:year, '-', :month, '-01'), '%Y-%m-%d')
    GROUP BY
        year, month
    ORDER BY
        year DESC, month DESC
    LIMIT 6
");
$forecastDataStmt->execute(['year' => $selectedYear, 'month' => $selectedMonth]);
// We fetch in reverse order and then flip it for correct chronological order for the regression
$forecastData = array_reverse($forecastDataStmt->fetchAll(PDO::FETCH_COLUMN));

// Add the current month's calculated total to the forecast data
if ($totalCalculated > 0) {
    $forecastData[] = $totalCalculated;
}

$nextMonthForecast = calculate_linear_regression($forecastData);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - Wages & Salaries Dashboard - hospital0</title>
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <link rel="icon" href="/media/sitelogo.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-image: none !important; background-color: #121212 !important; color: #fff; overflow-x: hidden; }
        @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
        .stars, .twinkling { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; display: block; z-index: -3; }
        .stars { background: #000 url(/media/stars.png) repeat top center; }
        .twinkling { background: transparent url(/media/twinkling.png) repeat top center; animation: move-twink-back 200s linear infinite; }
        #dna-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; opacity: 0.3; }
        h3, h5 { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
        .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
        .container { max-width: 1800px; width: 95%; }
        
        .glass-card { 
            background: rgba(255, 255, 255, 0.05); 
            backdrop-filter: blur(15px); 
            -webkit-backdrop-filter: blur(15px); 
            border: 1px solid rgba(255, 255, 255, 0.1); 
            border-radius: 16px; 
            padding: 1.5rem 2rem; 
            margin-top: 1.5rem;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.2);
        }
        
        .stat-box { 
            background: rgba(0, 0, 0, 0.2); 
            padding: 25px 20px; 
            border-radius: 12px; 
            text-align: center; 
            border: 1px solid rgba(255,255,255,0.1); 
            height: 100%; 
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .stat-box h5 { 
            margin: 0 0 10px 0; 
            font-size: 1.0rem; 
            color: #bdbdbd; 
            font-weight: 500;
            text-transform: uppercase; 
            letter-spacing: 0.5px;
        }
        .stat-box p { 
            margin: 0; 
            font-size: 2.3rem; 
            font-weight: 600; 
            line-height: 1.2;
        }
        .stat-box .forecast-note {
            font-size: 0.8rem;
            color: #9e9e9e;
            margin-top: 5px;
            font-weight: 300;
        }
        
        table.striped>tbody>tr:nth-child(odd) { background-color: rgba(255, 255, 255, 0.04); }
        th { border-bottom: 1px solid rgba(255, 255, 255, 0.3); } td, th { padding: 15px 10px; }

        .input-field input, .input-field .select-dropdown, .input-field textarea { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
        .input-field label { color: #bdbdbd !important; } .input-field label.active { color: #00e5ff !important; }
        .input-field input:focus, .input-field textarea:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
        ul.dropdown-content { background-color: #2a2a2a; } .dropdown-content li>span { color: #fff !important; }
        
        .modal { background-color: #2a2a2a; color: #fff; border-radius: 15px; border: 1px solid rgba(255, 255, 255, 0.2); }
        .modal .modal-content h4 { font-weight: 300; color: #00e5ff; }
        .modal .modal-footer { background-color: transparent; }
        
        .chip { color: rgba(255,255,255,0.9); font-weight: bold; }
        .chip.green { background-color: #2e7d32; }
        .chip.grey { background-color: #616161; }
        .note-icon { vertical-align: middle; cursor: pointer; margin-left: 8px; opacity: 0.7; }
        .note-icon:hover { opacity: 1; }

        .chart-container {
            min-height: 350px;
        }
        .chart-title {
            color: #e0e0e0;
            font-size: 1.2rem;
            font-weight: 400;
            text-align: center;
            margin-bottom: 15px;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
    <h3 class="center-align white-text">Payroll Dashboard & Disbursements</h3>
    <h5 class="center-align grey-text text-lighten-1"><?= htmlspecialchars($pageTitle) ?></h5>
    <hr class="white-line">

    <div class="glass-card">
        <div class="row" style="margin-bottom:0;">
            <div class="col s12 m6 l4 xl2" style="margin-bottom: 1rem;">
                <div class="stat-box">
                    <h5>Calculated (Month)</h5>
                    <p class="cyan-text text-lighten-2">PKR <?= number_format($totalCalculated, 0) ?></p>
                </div>
            </div>
            <div class="col s12 m6 l4 xl2" style="margin-bottom: 1rem;">
                <div class="stat-box">
                    <h5>Disbursed (Month)</h5>
                    <p class="green-text text-lighten-2">PKR <?= number_format($totalDisbursed, 0) ?></p>
                </div>
            </div>
            <div class="col s12 m6 l4 xl2" style="margin-bottom: 1rem;">
                <div class="stat-box">
                    <h5>Next Month Forecast</h5>
                    <p class="yellow-text text-lighten-2">PKR <?= number_format($nextMonthForecast, 0) ?></p>
                    <span class="forecast-note">(Based on 6-mo. trend)</span>
                </div>
            </div>
            <div class="col s12 m6 l4 xl2" style="margin-bottom: 1rem;">
                <div class="stat-box">
                    <h5>Total Hours (Month)</h5>
                    <p class="white-text"><?= number_format($totalHoursLogged, 1) ?></p>
                </div>
            </div>
            <div class="col s12 m6 l4 xl2" style="margin-bottom: 1rem;">
                <div class="stat-box">
                    <h5>Disbursed Staff</h5>
                    <p class="white-text"><?= $disbursementsMade ?></p>
                </div>
            </div>
            <div class="col s12 m6 l4 xl2" style="margin-bottom: 1rem;">
                <div class="stat-box">
                    <h5>Pending Staff</h5>
                    <p class="white-text"><?= $disbursementsPending ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="glass-card">
        <div class="row">
            <div class="col s12 l7">
                <h5 class="chart-title">12-Month Payroll (Calculated vs. Disbursed)</h5>
                <div id="historicalChart" class="chart-container"></div>
            </div>
            <div class="col s12 l5">
                <div class="row">
                    <div class="col s12 m6 l12">
                         <h5 class="chart-title">Disbursement Progress (<?= htmlspecialchars(date('F Y', strtotime($selectedYearMonth . '-01'))) ?>)</h5>
                        <div id="progressDonutChart" class="chart-container"></div>
                    </div>
                    <div class="col s12 m6 l12">
                        <h5 class="chart-title">Payroll by Department (<?= htmlspecialchars(date('F Y', strtotime($selectedYearMonth . '-01'))) ?>)</h5>
                        <div id="departmentPieChart" class="chart-container"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="glass-card">
        <div class="row">
            <div class="input-field col s12 m6 offset-m3">
                <select id="monthSelector" onchange="window.location.href='wages.php?month='+this.value">
                    <?php foreach ($last12Months as $ym => $label): ?>
                        <option value="<?= $ym; ?>" <?= ($ym === $selectedYearMonth) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label>View Records for Month</label>
            </div>
        </div>
    </div>

    <div class="glass-card">
        <table class="striped highlight responsive-table">
            <thead>
                <tr>
                    <th>User</th><th>Group</th>
                    <th class="right-align">Base Salary</th>
                    <th class="right-align">Expected Hrs</th>
                    <th class="right-align">Worked Hrs</th>
                    <th class="right-align">Calculated Pay</th>
                    <th class="center-align">Status / Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($wageData as $userId => $data): ?>
                    <tr>
                        <td><?= htmlspecialchars($data['full_name']); ?></td>
                        <td><span class="grey-text text-lighten-1"><?= htmlspecialchars($data['group_name']); ?></span></td>
                        <td class="right-align"><?= number_format($data['base_salary']); ?></td>
                        <td class="right-align"><?= number_format($data['expected_hours'], 1); ?></td>
                        <td class="right-align <?= ($data['hours_worked'] < $data['expected_hours'] * 0.9) ? 'red-text text-lighten-2' : 'green-text text-lighten-2'; ?>">
                            <?= number_format($data['hours_worked'], 2); ?>
                        </td>
                        <td class="right-align cyan-text text-lighten-3" style="font-weight: 700; font-size: 1.1rem;">
                            <?= number_format($data['calculated_pay'], 2); ?>
                        </td>
                        <td class="center-align">
                            
                            <?php if ($data['status'] === 'Paid'): ?>
                                <span class="white-text" style="font-weight: 500;">
                                    Paid: PKR <?= number_format($data['disbursed_amount'], 0) ?>
                                </span>
                                <?php if (!empty($data['notes'])): ?>
                                    <i class="material-icons note-icon tooltipped" data-position="top" data-tooltip="<?= htmlspecialchars($data['notes']); ?>">note</i>
                                <?php endif; ?>
                            
                            <?php elseif (!$canDisburse): ?>
                                <div class="chip grey">Pending</div>
                            <?php else: ?>
                                <button class="btn waves-effect waves-light modal-trigger" style="background-color:#00bfa5;"
                                        data-target="disburseModal"
                                        data-userid="<?= $userId; ?>"
                                        data-username="<?= htmlspecialchars($data['full_name']); ?>"
                                        data-calculatedpay="<?= $data['calculated_pay']; ?>">
                                    Disburse
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                 <?php if (empty($wageData)): ?>
                    <tr><td colspan="7" class="center-align grey-text">No active users to display for this period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<br>

<div id="disburseModal" class="modal">
    <form method="POST" action="wages.php?month=<?= $selectedYearMonth ?>">
        <div class="modal-content">
            <h4 id="modal-title">Disburse Salary</h4>
            <p>Calculated amount for <strong id="modal-username"></strong> is PKR <strong id="modal-calculated-pay"></strong>.</p>
            <input type="hidden" name="user_id" id="modal-user-id">
            <div class="row">
                <div class="input-field col s12">
                    <i class="material-icons prefix">attach_money</i>
                    <input id="disbursed_amount" name="disbursed_amount" type="number" step="0.01" class="validate" required>
                    <label for="disbursed_amount">Final Disbursement Amount (PKR)</label>
                </div>
                <div class="input-field col s12">
                    <i class="material-icons prefix">note</i>
                    <textarea id="notes" name="notes" class="materialize-textarea"></textarea>
                    <label for="notes">Notes (Optional, e.g., bonus, deduction reason)</label>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <a href="#!" class="modal-close waves-effect waves-grey btn-flat">Cancel</a>
            <button type="submit" name="disburse_salary" class="btn waves-effect waves-light" style="background-color:#00bfa5;">Confirm Disbursement</button>
        </div>
    </form>
</div>


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
    // Initialize Materialize components
    M.AutoInit();
    
    var modalElems = document.querySelectorAll('.modal');
    M.Modal.init(modalElems, {
        onOpenStart: function(modal, trigger) {
            const userId = trigger.getAttribute('data-userid');
            const userName = trigger.getAttribute('data-username');
            const calculatedPay = trigger.getAttribute('data-calculatedpay');
            
            modal.querySelector('#modal-username').textContent = userName;
            modal.querySelector('#modal-calculated-pay').textContent = parseFloat(calculatedPay).toFixed(2);
            modal.querySelector('#modal-user-id').value = userId;
            const amountInput = modal.querySelector('#disbursed_amount');
            amountInput.value = parseFloat(calculatedPay).toFixed(2);

            M.updateTextFields();
        }
    });

    var tooltipElems = document.querySelectorAll('.tooltipped');
    M.Tooltip.init(tooltipElems);
    
    // --- 🌟 NEW: APEXCHARTS DASHBOARD INITIALIZATION 🌟 ---

    // 1. Inject PHP data into JavaScript
    const chartLabels = <?= json_encode($chartLabels); ?>;
    const chartCalculated = <?= json_encode($chartCalculated); ?>;
    const chartDisbursed = <?= json_encode($chartDisbursed); ?>;
    
    const progressDisbursed = <?= $disbursementsMade; ?>;
    const progressPending = <?= $disbursementsPending; ?>;
    const progressTotal = <?= $totalUsersProcessed; ?>;
    const progressPercent = (progressTotal > 0) ? Math.round((progressDisbursed / progressTotal) * 100) : 0;

    const deptLabels = <?= json_encode($departmentLabels); ?>;
    const deptValues = <?= json_encode($departmentValues); ?>;

    // --- Chart 1: Historical Payroll (Area Chart) ---
    var historicalChartOptions = {
        series: [{
            name: 'Total Calculated',
            data: chartCalculated
        }, {
            name: 'Total Disbursed',
            data: chartDisbursed
        }],
        chart: {
            height: 350,
            type: 'area',
            foreColor: '#e0e0e0',
            toolbar: { show: false },
            zoom: { enabled: false }
        },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        colors: ['#00e5ff', '#00bfa5'],
        xaxis: {
            type: 'category',
            categories: chartLabels,
            labels: {
                style: { colors: '#9e9e9e' }
            },
            axisBorder: { show: false },
            axisTicks: { show: false }
        },
        yaxis: {
            labels: {
                style: { colors: '#9e9e9e' },
                formatter: (val) => { return `PKR ${val.toLocaleString()}` }
            }
        },
        grid: {
            borderColor: 'rgba(255, 255, 255, 0.1)',
            strokeDashArray: 4
        },
        fill: {
            type: 'gradient',
            gradient: {
                shade: 'dark',
                type: "vertical",
                shadeIntensity: 0.5,
                opacityFrom: 0.7,
                opacityTo: 0.1,
                stops: [0, 100]
            }
        },
        tooltip: {
            theme: 'dark',
            x: { format: 'MMM yyyy' },
            y: {
                formatter: (val) => { return `PKR ${val.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` }
            }
        },
        legend: {
            position: 'top',
            horizontalAlign: 'left',
            markers: { radius: 12 }
        }
    };
    var historicalChart = new ApexCharts(document.querySelector("#historicalChart"), historicalChartOptions);
    historicalChart.render();

    // --- Chart 2: Disbursement Progress (Radial Bar) ---
    var progressChartOptions = {
        series: [progressPercent],
        chart: {
            height: 350,
            type: 'radialBar',
            offsetY: -10
        },
        plotOptions: {
            radialBar: {
                startAngle: -135,
                endAngle: 135,
                hollow: {
                    margin: 0,
                    size: '70%',
                    background: 'transparent',
                },
                dataLabels: {
                    name: {
                        show: true,
                        offsetY: -10,
                        fontSize: '16px',
                        color: '#9e9e9e',
                        formatter: function(w) {
                            return `${progressDisbursed} / ${progressTotal} Staff`
                        }
                    },
                    value: {
                        formatter: function(val) {
                            return val.toFixed(0) + "%";
                        },
                        fontSize: '36px',
                        color: '#ffffff',
                        show: true,
                        offsetY: 10,
                    }
                }
            }
        },
        fill: {
            type: 'gradient',
            gradient: {
                shade: 'dark',
                type: 'horizontal',
                shadeIntensity: 0.5,
                gradientToColors: ['#00bfa5'],
                inverseColors: true,
                opacityFrom: 1,
                opacityTo: 1,
                stops: [0, 100]
            }
        },
        stroke: { lineCap: 'round' },
        labels: ['Disbursed'],
        colors: ['#00e5ff'],
    };
    var progressDonutChart = new ApexCharts(document.querySelector("#progressDonutChart"), progressChartOptions);
    progressDonutChart.render();

    // --- 🌟 REQUEST 1: ENHANCED DONUT CHART 🌟 ---
    var departmentPieOptions = {
        series: deptValues,
        labels: deptLabels,
        chart: {
            type: 'donut', // <-- Changed from 'pie' to 'donut'
            height: 350,
            foreColor: '#e0e0e0',
        },
        // 🌟 Added a modern color palette
        colors: ['#00E396', '#008FFB', '#FEB019', '#FF4560', '#775DD0', '#546E7A', '#D10CE8'],
        plotOptions: {
            pie: {
                donut: {
                    size: '65%', // Adjust donut thickness
                    labels: {
                        show: true,
                        total: {
                            show: true,
                            label: 'Total Payroll',
                            color: '#bdbdbd',
                            fontSize: '16px',
                            formatter: function (w) {
                                const total = w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                                return 'PKR ' + total.toLocaleString(undefined, { maximumFractionDigits: 0 });
                            }
                        },
                        value: {
                            color: '#ffffff',
                            fontSize: '24px',
                            fontWeight: 600,
                            formatter: function (val) {
                                // This shows the value of the hovered slice
                                return 'PKR ' + parseFloat(val).toLocaleString(undefined, { maximumFractionDigits: 0 });
                            }
                        }
                    }
                }
            }
        },
        legend: {
            position: 'bottom',
            horizontalAlign: 'center',
            markers: { radius: 12 },
            itemMargin: { horizontal: 10, vertical: 5 }
        },
        tooltip: {
            theme: 'dark',
            y: {
                title: {
                    formatter: function (seriesName) {
                        return seriesName;
                    }
                },
                formatter: (val) => { return `PKR ${val.toLocaleString()}` }
            }
        },
        dataLabels: {
            enabled: true,
            // 🌟 Changed: Cleaner percentage format
            formatter: function (val) {
                return val.toFixed(1) + '%'
            },
            style: {
                fontSize: '14px',
                fontWeight: 'bold',
            },
            dropShadow: {
                enabled: true,
                top: 1,
                left: 1,
                blur: 1,
                opacity: 0.45
            }
        },
        responsive: [{
            breakpoint: 480,
            options: {
                chart: {
                    width: '100%' // Better responsiveness
                },
                legend: {
                    position: 'bottom'
                }
            }
        }]
    };
    
    var departmentPieChart = new ApexCharts(document.querySelector("#departmentPieChart"), departmentPieOptions);
    if(deptValues.length > 0) {
        departmentPieChart.render();
    } else {
        document.querySelector("#departmentPieChart").innerHTML = '<p class="center-align grey-text" style="padding-top: 100px;">No payroll data to display for this month.</p>';
    }

});
</script>
</body>
</html>