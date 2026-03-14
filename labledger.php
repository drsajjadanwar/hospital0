<?php
// /public/labledger.php   – 29 Apr 2025 (r2)
// • Filter drop-down (Today, Yesterday, … Year-to-Date) with live update
// • 20-row pagination (client-side)          • “Add” form shown only to group_id 1
// • Lightweight JSON endpoint (?ajax=1) used by the page for real-time refresh

session_start();
require_once __DIR__ . '/includes/config.php';

/* ---------- who can view ---------- */
$allowedGroups = [1, 5, 7, 8, 12];
if (!isset($_SESSION['user']) ||
    !in_array((int)$_SESSION['user']['group_id'], $allowedGroups, true)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}
$user      = $_SESSION['user'];
$is_admin  = ($user['group_id'] == 1);
$tbl       = 'labledger';

/* ---------- filter helper ---------- */
function sqlWhereForFilter(string $f): string
{
    return match ($f) {
        'Today'          => 'DATE(datetime)=CURDATE()',
        'Yesterday'      => 'DATE(datetime)=CURDATE()-INTERVAL 1 DAY',
        'This Week'      => 'YEARWEEK(datetime,3)=YEARWEEK(CURDATE(),3)',
        'This Month'     => 'YEAR(datetime)=YEAR(CURDATE()) AND MONTH(datetime)=MONTH(CURDATE())',
        'Last Month'     => 'YEAR(datetime)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND MONTH(datetime)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))',
        'Month to Date'  => 'YEAR(datetime)=YEAR(CURDATE()) AND MONTH(datetime)=MONTH(CURDATE())',
        'Year to Date'   => 'YEAR(datetime)=YEAR(CURDATE())',
        default          => ''          /* “All” */
    };
}

/* ---------- fetch data (optionally JSON) ---------- */
$filter    = $_GET['filter'] ?? 'All';
$whereSQL  = sqlWhereForFilter($filter);
$wherePart = $whereSQL ? "WHERE $whereSQL" : '';

$rows = $pdo
    ->query("SELECT * FROM $tbl $wherePart ORDER BY datetime DESC")
    ->fetchAll(PDO::FETCH_ASSOC);

$totalCred = $pdo->query(
    "SELECT COALESCE(SUM(amount),0) FROM $tbl $wherePart" .
    ($whereSQL ? " AND amount>0" : " WHERE amount>0")
)->fetchColumn();

$totalDeb = abs($pdo->query(
    "SELECT COALESCE(SUM(amount),0) FROM $tbl $wherePart" .
    ($whereSQL ? " AND amount<0" : " WHERE amount<0")
)->fetchColumn());

$curAcc = $totalCred - $totalDeb;
$totEnt = count($rows);
$latest = $rows[0]['datetime'] ?? 'N/A';

/* ---------- JSON endpoint ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'rows'       => $rows,
        'totCred'    => $totalCred,
        'totDeb'     => $totalDeb,
        'curAcc'     => $curAcc,
        'totEnt'     => $totEnt,
        'latest'     => $latest,
    ]);
    exit;
}

/* ---------- flash on add ---------- */
$flashErr = $flashOk = '';
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ledger_submit'])) {
    $amt  = trim($_POST['amount_pkr'] ?? '');
    $dr   = trim($_POST['drcr'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($amt === '' || $dr === '') {
        $flashErr = 'Fill all fields.';
    } else {
        $val = (float) $amt;
        $val = strcasecmp($dr, 'Debit') === 0 ? -abs($val) : abs($val);
        $pdo->prepare("INSERT INTO $tbl (datetime,description,amount,user)
                       VALUES (NOW(),?,?,?)")
            ->execute([$desc, $val, $user['username']]);
        $flashOk = 'Entry saved.';
        header('Location: labledger.php');   // avoid resubmission
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>hospital0 - Lab Ledger — hospital0</title>
<link rel="icon" href="/media/sitelogo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
<link rel="stylesheet" href="assets/css/style.css">
<style>
.white-line   {width:50%;background:#fff;height:2px;border:none;margin:20px auto;}
.neg          {color:red;font-weight:bold;}   .pos {color:#2e7d32;font-weight:bold;}
.banner-ok,
.banner-err   {color:#fff;padding:8px;margin:15px 0;}
.banner-ok    {background:#2e7d32;}  .banner-err {background:#c62828;}
.big-number   {display:inline-block;text-align:center;color:#fff;}
.big-number::after{content:"";display:block;width:100%;height:2px;background:#fff;}
.pagination li.active   a{background:#26a69a;}
.pagination li.disabled a{color:#999;}
</style>
</head>
<body>
<?php include_once __DIR__.'/includes/header.php'; ?>

<div class="container">
  <h3 class="center-align" style="margin-top:30px;">Laboratory Ledger</h3>
  <hr class="white-line">

  <!-- filters -->
  <div class="row center-align">
    <div class="input-field col s12 m6 offset-m3">
      <select id="filterSel">
        <?php
          $opts = ['All','Today','Yesterday','This Week','This Month','Last Month','Month to Date','Year to Date'];
          foreach($opts as $opt){
              $sel = $opt===$filter ? 'selected' : '';
              echo "<option value=\"$opt\" $sel>$opt</option>";
          }
        ?>
      </select>
      <label>Filter By Date</label>
    </div>
  </div>

  <?php if($flashErr): ?><div class="banner-err center-align"><?=htmlspecialchars($flashErr)?></div><?php endif;?>
  <?php if($flashOk): ?><div class="banner-ok  center-align"><?=htmlspecialchars($flashOk)?></div><?php endif;?>

  <!-- Ledger in a Glance -->
  <h5 id="glance_hdr" class="center-align white-text" style="margin-bottom:30px;">Ledger In a Glance…</h5>
  <div id="glance_boxes">
    <!-- JS populates -->
  </div>

  <!-- Admin add form -->
  <?php if($is_admin): ?>
  <form id="addForm" method="POST" class="row">
    <div class="input-field col s12 m3"><input type="number" step="0.01" name="amount_pkr" required><label class="active">Amount PKR</label></div>
    <div class="input-field col s12 m3">
      <select name="drcr" required>
        <option value="" disabled selected>Choose</option>
        <option>Credit</option><option>Debit</option>
      </select><label class="active">Type</label>
    </div>
    <div class="input-field col s12 m4"><input type="text" name="description" required><label class="active">Description</label></div>
    <div class="col s12 m2" style="margin-top:25px;"><button class="btn" name="ledger_submit" style="width:100%;">Add</button></div>
  </form>
  <?php endif; ?>

  <hr class="white-line">

  <!-- Table -->
  <table id="ledgerTable" class="striped responsive-table">
    <thead><tr><th>ID</th><th>Date/Time</th><th>Description</th><th>Amount</th><th>User</th></tr></thead>
    <tbody></tbody>
  </table>

  <!-- pagination -->
  <ul id="pager" class="pagination center-align"></ul>
</div>

<?php include_once __DIR__.'/includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const sel     = document.getElementById('filterSel');
  const tbody   = document.querySelector('#ledgerTable tbody');
  const pager   = document.getElementById('pager');
  const boxes   = document.getElementById('glance_boxes');
  const PER     = 20;
  let   data    = [];       // full result set
  let   curPage = 1;

  M.FormSelect.init(document.querySelectorAll('select'));

  sel.addEventListener('change', () => fetchData(sel.value));
  fetchData(sel.value);

  function fetchData(filter){
    fetch(`labledger.php?ajax=1&filter=${encodeURIComponent(filter)}`)
      .then(r => r.json())
      .then(js => {
        data = js.rows;
        curPage = 1;
        updateGlance(js);
        renderTable();
        renderPager();
      });
  }

  function updateGlance(js){
    boxes.innerHTML = `
      <div class="row" style="margin-bottom:40px;">
        <div class="col s12 m4 center-align"><div class="big-number"><h3>PKR ${n(js.totCred)}</h3></div><div>Total Credited</div></div>
        <div class="col s12 m4 center-align"><div class="big-number"><h3>PKR ${n(js.totDeb)}</h3></div><div>Total Debited</div></div>
        <div class="col s12 m4 center-align"><div class="big-number"><h3>PKR ${n(js.curAcc)}</h3></div><div>Current Balance</div></div>
      </div>
      <div class="row" style="margin-bottom:40px;">
        <div class="col s12 m4 center-align"><div class="big-number"><h3>${js.totEnt}</h3></div><div>Total Entries</div></div>
        <div class="col s12 m4 center-align"><div class="big-number"><h3>${js.latest}</h3></div><div>Latest Entry</div></div>
        <div class="col s12 m4 center-align"><div class="big-number"><h3>—</h3></div><div>Reserved</div></div>
      </div>`;
  }

  function renderTable(){
    tbody.innerHTML = '';
    const start = (curPage-1)*PER, end = start+PER;
    data.slice(start,end).forEach(r=>{
      const cls = r.amount < 0 ? 'neg' : 'pos';
      tbody.insertAdjacentHTML('beforeend',
        `<tr>
           <td>${r.serial_number}</td>
           <td>${r.datetime}</td>
           <td>${escapeHtml(r.description)}</td>
           <td class="${cls}">${n(r.amount)}</td>
           <td>${escapeHtml(r.user)}</td>
         </tr>`);
    });
  }

  function renderPager(){
    pager.innerHTML = '';
    const pages = Math.max(1, Math.ceil(data.length / PER));
    const disabled = (p) => `<li class="disabled"><a href="#!">${p}</a></li>`;
    const active   = (p) => `<li class="active"><a href="#!">${p}</a></li>`;
    const link     = (p, lbl=p) => `<li class="waves-effect"><a href="#!" data-page="${p}">${lbl}</a></li>`;

    pager.insertAdjacentHTML('beforeend',
      curPage===1 ? disabled('«') : link(curPage-1,'«'));

    for(let p=1; p<=pages; p++){
      pager.insertAdjacentHTML('beforeend',
        p===curPage ? active(p) : link(p));
    }

    pager.insertAdjacentHTML('beforeend',
      curPage===pages ? disabled('»') : link(curPage+1,'»'));

    pager.querySelectorAll('a[data-page]').forEach(a=>{
      a.addEventListener('click',e=>{
        e.preventDefault();
        curPage = parseInt(a.dataset.page,10);
        renderTable(); renderPager();
      });
    });
  }

  /* helpers */
  const n = v => parseFloat(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
  function escapeHtml(str){
    return str.replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
  }
});
</script>
</body></html>
