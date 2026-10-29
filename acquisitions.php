<?php
// Acquisitions (purchases, gifts, donations): list, add / edit, discount, net price, budget and balance, and a department summary.
//   ?v=records (default)   ?v=depts        ?a=add   ?a=edit&id=
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/acquisitions.php';
$pdo = db();
$pageTitle = 'Acquisitions';
$base = 'acquisitions.php';
$ed = can_edit_records();
$adm = is_role('admin');   // only the administrator enters or sees prices, discounts and budgets

// The new columns come from 07_acquisitions.sql. Say so clearly if it has not been imported yet.
try { $pdo->query('SELECT Disc_type, Disc_value, Budget, Format, Run_time, Producer FROM acquitemp LIMIT 1'); }
catch (PDOException $ex) {
    include __DIR__ . '/includes/header.php';
    echo '<h1>Acquisitions</h1><div class="card"><p>The acquisition table needs updating. Import <code>07_acquisitions.sql</code> in phpMyAdmin, then reload this page.</p></div>';
    include __DIR__ . '/includes/footer.php'; exit;
}

// Optional columns (edition, recommended by, where selected, foreign price) come from 11_gap_fixes.sql; the page works without them.
$ACQ_EXTRA = false;
try { $pdo->query('SELECT Edition, Recom_by, Selected_where, Fx_foreign, Fx_rate FROM acquitemp LIMIT 1'); $ACQ_EXTRA = true; } catch (PDOException $ex) {}
$GLOBALS['ACQ_EXTRA'] = $ACQ_EXTRA;
$a = $_GET['a'] ?? ''; $id = (int)($_GET['id'] ?? 0);
$formError = '';
$cols = ['Title', 'Author', 'Publisher', 'ISBN', 'Ye_ar', 'Quantity', 'Price', 'Da_te', 'Status', 'Mode', 'MType', 'Dept', 'Dealer', 'Disc_type', 'Disc_value', 'Budget', 'Format', 'Run_time', 'Producer'];
if ($ACQ_EXTRA) $cols = array_merge($cols, ['Edition', 'Recom_by', 'Selected_where', 'Fx_foreign', 'Fx_rate']);
$row = array_fill_keys($cols, ''); $row['Quantity'] = 1; $row['MType'] = 'Book'; $row['Da_te'] = date('Y-m-d'); $row['Mode'] = 'Purchase';
$editId = 0;

// ---------- actions (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$ed) deny();
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'delete') {
        $pdo->prepare('DELETE FROM acquitemp WHERE ID = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Record deleted.'); redirect($base);
    }
    if ($do === 'save') {
        $editId = (int)($_POST['id'] ?? 0);
        [$vals, $formError] = acq_clean($_POST, $adm);
        if ($formError === '' && $editId) { $ck = $pdo->prepare('SELECT 1 FROM acquitemp WHERE ID = ?'); $ck->execute([$editId]); if (!$ck->fetchColumn()) { flash('Not done: record not found.'); redirect($base); } }
        if ($formError === '') {
            $set = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($vals)));
            if ($editId) { $vals['__id'] = $editId; $pdo->prepare("UPDATE acquitemp SET $set WHERE ID = :__id")->execute($vals); flash('Changes saved.'); }
            else { $names = implode(', ', array_map(fn($c) => "`$c`", array_keys($vals))); $ph = implode(', ', array_map(fn($c) => ":$c", array_keys($vals)));
                   $pdo->prepare("INSERT INTO acquitemp ($names) VALUES ($ph)")->execute($vals); flash('Record added.'); }
            redirect($base);
        }
        $row = array_merge($row, array_map(fn($x) => $x ?? '', $vals)); $a = $editId ? 'edit' : 'add'; $id = $editId;
    }
}

// ---------- add / edit form ----------
if ($a === 'add' || $a === 'edit') {
    if (!$ed) deny();
    if ($a === 'edit' && $formError === '') {
        $st = $pdo->prepare('SELECT * FROM acquitemp WHERE ID = ?'); $st->execute([$id]); $r = $st->fetch();
        if (!$r) { flash('Not done: record not found.'); redirect($base); }
        foreach ($cols as $c) $row[$c] = $r[$c] ?? '';
        $row['Da_te'] = $row['Da_te'] ? substr($row['Da_te'], 0, 10) : '';
    }
    $depts = acq_depts(); $dealers = acq_dealers(); $fig = acq_figures($row);
    include __DIR__ . '/includes/header.php'; ?>
<h1><?= $a === 'add' ? 'Add' : 'Edit' ?> · Acquisition</h1>
<?php if ($formError): ?><div class="err" role="alert"><?= e($formError) ?></div><?php endif; ?>
<form method="post" class="card form" autocomplete="off" id="acqform">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$id ?>">
  <label><span>Material type</span><select name="MType" id="mtype"><?php foreach (ACQ_MTYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $row['MType'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <label><span>Title</span><textarea name="Title" rows="2" maxlength="250" required><?= e($row['Title']) ?></textarea></label>
  <label><span>Author</span><input type="text" name="Author" maxlength="80" value="<?= e($row['Author']) ?>"></label>
  <label><span>Publisher</span><input type="text" name="Publisher" maxlength="80" value="<?= e($row['Publisher']) ?>"></label>
  <label><span>ISBN</span><input type="text" name="ISBN" maxlength="50" value="<?= e($row['ISBN']) ?>"></label>
  <label><span>Year</span><input type="text" name="Ye_ar" maxlength="20" value="<?= e($row['Ye_ar']) ?>"></label>
  <?php if ($ACQ_EXTRA): ?><label><span>Edition</span><input type="text" name="Edition" maxlength="40" value="<?= e($row['Edition']) ?>"></label><?php endif; ?>
  <div id="eqbox" class="form" style="max-width:none"<?= $row['MType'] === 'EQ' ? '' : ' hidden' ?>>
    <label><span>Kind of equipment (e.g. cassette player)</span><input type="text" name="Format" maxlength="30" value="<?= $row['MType'] === 'EQ' ? e($row['Format']) : '' ?>" <?= $row['MType'] === 'EQ' ? '' : 'disabled' ?> id="eqtype"></label>
  </div>
  <div id="avbox" class="form" style="max-width:none"<?= $row['MType'] === 'SM' ? '' : ' hidden' ?>>
    <label><span>Format (DVD, CD, VCD …)</span><input type="text" name="Format" id="avformat" maxlength="30" list="fmts" value="<?= $row['MType'] === 'SM' ? e($row['Format']) : '' ?>" <?= $row['MType'] === 'SM' ? '' : 'disabled' ?>><datalist id="fmts"><?php foreach (ACQ_FORMATS as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist></label>
    <label><span>Running time (e.g. 1h 45m)</span><input type="text" name="Run_time" maxlength="20" value="<?= e($row['Run_time']) ?>"></label>
    <label><span>Producer or director</span><input type="text" name="Producer" maxlength="100" value="<?= e($row['Producer']) ?>"></label>
  </div>
  <label><span>Copies</span><input type="number" name="Quantity" id="qty" min="1" max="100000" step="1" value="<?= e($row['Quantity']) ?>" required></label>
  <?php if ($adm): ?>
  <?php if ($ACQ_EXTRA): ?>
  <label><span>Currency: foreign price per copy (leave empty if bought in pesos)</span><input type="number" name="Fx_foreign" id="fxf" min="0" step="any" value="<?= e($row['Fx_foreign']) ?>"></label>
  <label><span>Currency: pesos for 1 unit of the foreign currency</span><input type="number" name="Fx_rate" id="fxr" min="0" step="any" value="<?= e($row['Fx_rate']) ?>" placeholder="1"></label>
  <?php endif; ?>
  <label><span>Price per copy (<?= e($CONFIG['circulation']['currency'] ?? '₱') ?>)</span><input type="number" name="Price" id="price" min="0" step="any" value="<?= e($row['Price']) ?>"></label>
  <label><span>Discount</span><span style="display:flex;gap:8px"><input type="number" name="Disc_value" id="dval" min="0" step="any" value="<?= e($row['Disc_value']) ?>" style="flex:1" aria-label="Discount value">
    <select name="Disc_type" id="dtype" aria-label="Discount kind"><option value="percent" <?= $row['Disc_type'] !== 'amount' ? 'selected' : '' ?>>% off</option><option value="amount" <?= $row['Disc_type'] === 'amount' ? 'selected' : '' ?>><?= e($CONFIG['circulation']['currency'] ?? '₱') ?> off (all copies)</option></select></span></label>
  <p class="muted" style="margin:0">Gross <strong id="gross"><?= e(acq_money($fig['gross'])) ?></strong> · Discount <strong id="disc"><?= e(acq_money($fig['disc'])) ?></strong> · <b>Net price <span id="net"><?= e(acq_money($fig['net'])) ?></span></b></p>
  <label><span>Budget (for this record)</span><input type="number" name="Budget" id="budget" min="0" step="any" value="<?= e($row['Budget']) ?>"></label>
  <p class="muted" style="margin:0">Balance <strong id="bal"><?= $fig['balance'] === null ? '—' : e(acq_money($fig['balance'])) ?></strong> (budget − net price)</p>
  <?php else: ?><p class="muted" style="margin:0">Prices, discounts and budgets are entered by the administrator.</p><?php endif; ?>
  <label><span>Date</span><input type="date" name="Da_te" value="<?= e($row['Da_te']) ?>"></label>
  <label><span>Mode of acquisition</span><input type="text" name="Mode" maxlength="40" list="modes" value="<?= e($row['Mode']) ?>"><datalist id="modes"><?php foreach (ACQ_MODES as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist></label>
  <label><span>Status</span><input type="text" name="Status" maxlength="30" list="stats" value="<?= e($row['Status']) ?>"><datalist id="stats"><?php foreach (ACQ_STATUSES as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist></label>
  <label><span>Department</span><input type="text" name="Dept" maxlength="50" list="depts" value="<?= e($row['Dept']) ?>"><datalist id="depts"><?php foreach ($depts as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist></label>
  <label><span>Dealer</span><input type="text" name="Dealer" maxlength="80" list="dealers" value="<?= e($row['Dealer']) ?>"><datalist id="dealers"><?php foreach ($dealers as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist></label>
  <?php if ($ACQ_EXTRA): ?>
  <label><span>Recommended by</span><input type="text" name="Recom_by" maxlength="100" value="<?= e($row['Recom_by']) ?>"></label>
  <label><span>Where was it selected?</span><input type="text" name="Selected_where" maxlength="100" value="<?= e($row['Selected_where']) ?>" placeholder="e.g. book fair, bookstore, catalog"></label>
  <?php endif; ?>
  <div class="actions"><button class="btn primary">Save</button> <a class="btn" href="<?= e($base) ?>">Cancel</a></div>
</form>
<script>
(function(){
  var $=function(i){return document.getElementById(i)}, cur=<?= json_encode($CONFIG['circulation']['currency'] ?? '₱') ?>;
  function m(n){return cur+(Math.round(n*100)/100).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}
  function calc(){
    if(!$('price')) return;
    var q=parseInt($('qty').value,10)||1, p=parseFloat($('price').value)||0, g=p*q, dv=parseFloat($('dval').value)||0, d=0;
    if(dv>0){ d=$('dtype').value==='percent'?g*Math.min(dv,100)/100:Math.min(dv,g); }
    var n=g-d; $('gross').textContent=m(g); $('disc').textContent=m(d); $('net').textContent=m(n);
    var b=$('budget').value; $('bal').textContent=b===''?'—':m((parseFloat(b)||0)-n);
  }
  ['qty','price','dval','dtype','budget'].forEach(function(i){if(!$(i))return;$(i).addEventListener('input',calc);$(i).addEventListener('change',calc)});
  function fx(){ if(!$('fxf')) return; var f=parseFloat($('fxf').value)||0, r=parseFloat($('fxr').value)||1; if(f>0){ $('price').value=(Math.round(f*r*100)/100); calc(); } }
  if($('fxf')){ $('fxf').addEventListener('input',fx); $('fxr').addEventListener('input',fx); }
  $('mtype').addEventListener('change',function(){
    var t=this.value; $('avbox').hidden=t!=='SM'; $('eqbox').hidden=t!=='EQ';
    $('avformat').disabled=t!=='SM'; $('eqtype').disabled=t!=='EQ';
  });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; exit; }

// ---------- list / summary ----------
$v = ($adm && ($_GET['v'] ?? 'records') === 'depts') ? 'depts' : 'records';
$q = trim($_GET['q'] ?? ''); $df = $_GET['dept'] ?? ''; $mt = $_GET['mt'] ?? '';
$from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? ''; $yr = preg_match('/^\d{4}$/', (string)($_GET['yr'] ?? '')) ? $_GET['yr'] : '';
if ($yr !== '') { $from = "$yr-01-01"; $to = "$yr-12-31"; }   // "By year" is just a whole-year date range
$okDate = fn($d) => ($x = DateTime::createFromFormat('Y-m-d', (string)$d)) && $x->format('Y-m-d') === $d;
if ($from !== '' && !$okDate($from)) $from = ''; if ($to !== '' && !$okDate($to)) $to = '';
$where = ['1=1']; $args = [];
if ($v === 'records') {
    if ($q !== '') { $like = []; foreach (['Title', 'Author', 'Publisher', 'ISBN', 'Dealer'] as $i => $c) { $like[] = "a.$c LIKE :q$i"; $args["q$i"] = "%$q%"; } $where[] = '(' . implode(' OR ', $like) . ')'; }
    if ($df !== '') { if ($df === '(none)') $where[] = "(a.Dept IS NULL OR a.Dept = '')"; else { $where[] = 'a.Dept = :d'; $args['d'] = $df; } }
    if (in_array($mt, ['Book', 'SM', 'EQ'], true)) { $where[] = $mt === 'Book' ? "(a.MType IS NULL OR a.MType NOT IN ('SM', 'EQ'))" : "a.MType = " . $pdo->quote($mt); }
}
if ($from !== '') { $where[] = 'a.Da_te >= :from'; $args['from'] = $from; }
if ($to !== '') { $where[] = 'a.Da_te <= :to'; $args['to'] = $to; }
$w = implode(' AND ', $where);
$G = acq_gross_sql(); $D = acq_disc_sql(); $N = acq_net_sql();
include __DIR__ . '/includes/header.php';
echo '<div class="head-row"><h1>Acquisitions</h1>' . ($ed ? '<a class="btn primary no-print" href="' . e($base . '?a=add') . '">+ Add</a>' : '') . '</div><p class="tabs no-print">';
foreach (($adm ? ['records' => 'Records', 'depts' => 'Departments'] : ['records' => 'Records']) as $k => $l) echo '<a class="' . ($k === $v ? 'on' : '') . '" href="' . e($base . '?v=' . $k) . '">' . e($l) . '</a>';
echo '</p>';
$depts = acq_depts();

if ($v === 'records'):
    $page = max(1, (int)($_GET['p'] ?? 1)); $per = $CONFIG['per_page'];
    $tot = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(COALESCE(NULLIF(a.Quantity,0),1)),0) copies, COALESCE(SUM($G),0) gross, COALESCE(SUM($D),0) disc, COALESCE(SUM($N),0) net FROM acquitemp a WHERE $w");
    $tot->execute($args); $t = $tot->fetch(); $total = (int)$t['n'];
    $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $st = $pdo->prepare("SELECT a.*, $G AS gross, $D AS disc, $N AS net FROM acquitemp a WHERE $w ORDER BY a.Da_te DESC, a.ID DESC LIMIT " . (int)$per . ' OFFSET ' . (($page - 1) * $per));
    $st->execute($args); $rows = $st->fetchAll();
    $qs = fn($p) => $base . '?' . http_build_query(['v' => 'records', 'q' => $q, 'dept' => $df, 'mt' => $mt, 'yr' => $yr, 'from' => $from, 'to' => $to, 'p' => $p]);
?>
<form class="toolbar no-print" method="get"><input type="hidden" name="v" value="records">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search title, author, publisher, ISBN, dealer…">
  <select name="dept" aria-label="Department"><option value="">All departments</option><?php foreach (array_merge($depts, ['(none)']) as $d): ?><option <?= $d === $df ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select>
  <select name="mt" aria-label="Material type"><option value="">Books and audio-visual</option><option value="Book" <?= $mt === 'Book' ? 'selected' : '' ?>>Books</option><option value="SM" <?= $mt === 'SM' ? 'selected' : '' ?>>Audio-visual / non-book</option><option value="EQ" <?= $mt === 'EQ' ? 'selected' : '' ?>>Equipment</option></select>
  <select name="yr" aria-label="By year"><option value="">By date range (or all years)</option><?php foreach ($pdo->query("SELECT DISTINCT YEAR(Da_te) FROM acquitemp WHERE Da_te IS NOT NULL ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN) as $y): ?><option value="<?= (int)$y ?>" <?= (string)$y === $yr ? 'selected' : '' ?>>By year: <?= (int)$y ?></option><?php endforeach; ?></select>
  <input type="date" name="from" value="<?= e($from) ?>" aria-label="From"> <input type="date" name="to" value="<?= e($to) ?>" aria-label="To">
  <button class="btn">List</button> <a class="btn ghost" href="<?= e($base) ?>">Clear</a> <button type="button" class="btn ghost" data-print>Print</button></form>
<div class="cards"><?php foreach (array_merge([['Records', number_format($total)], ['Copies', number_format($t['copies'])]], $adm ? [['Gross', acq_money($t['gross'])], ['Discount', acq_money($t['disc'])], ['Net price', acq_money($t['net'])]] : []) as [$l, $n]): ?><div class="card stat"><span class="num" style="font-size:26px"><?= e($n) ?></span><span><?= e($l) ?></span></div><?php endforeach; ?></div>
<p class="muted"><?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?> · page <?= $page ?> of <?= $pages ?></p>
<div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Date</th><th>Title</th><th>Type</th><th>Copies</th><?php if ($adm): ?><th>Price</th><th>Discount</th><th>Net</th><th>Budget</th><th>Balance</th><?php endif; ?><th>Dept</th><th>Dealer</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r): $net = (float)$r['net']; $disc = ($r['Disc_type'] ?? '') === 'percent' ? rtrim(rtrim(number_format((float)$r['Disc_value'], 2), '0'), '.') . '%' : (($r['Disc_type'] ?? '') === 'amount' ? acq_money($r['Disc_value']) : ''); ?>
<tr><td><?= e($r['Da_te'] ? substr($r['Da_te'], 0, 10) : '') ?></td><td><?= e(shorten($r['Title'], 70)) ?><?= in_array($r['MType'] ?? '', ['SM', 'EQ'], true) && $r['Format'] ? ' <span class="muted">(' . e($r['Format']) . ')</span>' : '' ?></td><td><?= ($r['MType'] ?? '') === 'SM' ? 'A-V' : (($r['MType'] ?? '') === 'EQ' ? 'Equipment' : 'Book') ?></td>
  <td><?= (int)$r['Quantity'] ?></td><?php if ($adm): ?><td><?= $r['Price'] === null ? '<span class="muted">pending</span>' : number_format((float)$r['Price'], 2) ?></td><td><?= e($disc) ?></td><td><?= $r['Price'] === null ? '' : number_format($net, 2) ?></td><?php endif; ?>
  <?php if ($adm): ?><td><?= $r['Budget'] === null ? '' : number_format((float)$r['Budget'], 2) ?></td><td><?= $r['Budget'] === null ? '' : number_format((float)$r['Budget'] - $net, 2) ?></td><?php endif; ?>
  <td><?= e($r['Dept']) ?></td><td><?= e($r['Dealer']) ?></td>
  <td class="no-print row-actions"><?php if ($ed): ?><a href="<?= e($base . '?a=edit&id=' . (int)$r['ID']) ?>">Edit</a><form method="post" data-confirm="Delete this record?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link danger">Delete</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="<?= $adm ? 12 : 7 ?>" class="muted">No records found.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($pages > 1): ?><nav class="pager no-print"><?php if ($page > 1): ?><a href="<?= e($qs($page - 1)) ?>">‹ Prev</a><?php endif; ?><span>Page <?= $page ?> / <?= $pages ?></span><?php if ($page < $pages): ?><a href="<?= e($qs($page + 1)) ?>">Next ›</a><?php endif; ?></nav><?php endif; ?>
<?php else:
    $st = $pdo->prepare("SELECT COALESCE(NULLIF(a.Dept,''), '(none)') AS Dept, COUNT(*) n, SUM(COALESCE(NULLIF(a.Quantity,0),1)) copies, SUM($G) gross, SUM($D) disc, SUM($N) net FROM acquitemp a WHERE $w GROUP BY COALESCE(NULLIF(a.Dept,''), '(none)') ORDER BY net DESC");
    $st->execute($args); $rows = $st->fetchAll();
    // Budget = the budget typed on the department's newest record (in the date range) that has one.
    $bs = $pdo->prepare("SELECT COALESCE(NULLIF(a.Dept,''), '(none)') AS Dept, a.Budget FROM acquitemp a JOIN (SELECT COALESCE(NULLIF(b.Dept,''), '(none)') AS d, MAX(b.ID) AS mid FROM acquitemp b WHERE b.Budget IS NOT NULL" . ($from !== '' ? ' AND b.Da_te >= :from' : '') . ($to !== '' ? ' AND b.Da_te <= :to' : '') . " GROUP BY COALESCE(NULLIF(b.Dept,''), '(none)')) m ON a.ID = m.mid");
    $bs->execute(array_intersect_key($args, ['from' => 1, 'to' => 1])); $bud = array_column($bs->fetchAll(), 'Budget', 'Dept');
    $sum = ['n' => 0, 'copies' => 0, 'gross' => 0, 'disc' => 0, 'net' => 0];
    $ranged = $from !== '' && $to !== '';   // a balance only makes sense for a period, so it needs both dates
?>
<form class="toolbar no-print" method="get"><input type="hidden" name="v" value="depts">
  <input type="date" name="from" value="<?= e($from) ?>" aria-label="From"> <input type="date" name="to" value="<?= e($to) ?>" aria-label="To">
  <button class="btn">Show</button> <a class="btn ghost" href="<?= e($base . '?v=depts') ?>">Clear</a> <button type="button" class="btn ghost" data-print>Print</button></form>
<div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Department</th><th>Records</th><th>Copies</th><th>Gross</th><th>Discount</th><th>Net spent</th><th>Budget (latest typed)</th><th>Balance</th></tr></thead><tbody>
<?php foreach ($rows as $r): foreach ($sum as $k => $_) $sum[$k] += (float)$r[$k]; $b = $bud[$r['Dept']] ?? null; ?>
<tr><td><?= e($r['Dept']) ?></td><td><?= number_format($r['n']) ?></td><td><?= number_format($r['copies']) ?></td><td><?= number_format((float)$r['gross'], 2) ?></td><td><?= number_format((float)$r['disc'], 2) ?></td><td><?= number_format((float)$r['net'], 2) ?></td>
  <td><?= $b === null ? '<span class="muted">none typed</span>' : number_format((float)$b, 2) ?></td><td><?= ($b === null || !$ranged) ? '' : number_format((float)$b - (float)$r['net'], 2) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8" class="muted">No records in this range.</td></tr><?php else: ?>
<tr><td><strong>Total</strong></td><td><strong><?= number_format($sum['n']) ?></strong></td><td><strong><?= number_format($sum['copies']) ?></strong></td><td><strong><?= number_format($sum['gross'], 2) ?></strong></td><td><strong><?= number_format($sum['disc'], 2) ?></strong></td><td><strong><?= number_format($sum['net'], 2) ?></strong></td><td></td><td></td></tr><?php endif; ?></tbody></table></div>
<p class="muted">Net spent = price × copies − discount. Budget is the one typed on the department's newest record in this range; balance = that budget − net spent in the range. Set both From and To dates to see balances.</p>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
