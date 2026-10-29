<?php
// Fine balance and payments: see what a borrower owes, take a payment, settle in full, waive a fine; payments list; estimate.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db();
$pageTitle = 'Balance & payments';
if (!ce_have_balance()) ce_need_sql('Balance & payments');

$t = $_GET['t'] ?? $_POST['t'] ?? 'balance';
if (!in_array($t, ['balance', 'payments', 'estimate'], true)) $t = 'balance';
if ($t === 'payments' && !can_edit_records()) $t = 'balance';   // the payments list is for the admin and librarians
$idnum = trim($_GET['idnum'] ?? $_POST['idnum'] ?? '');

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $by = $_SESSION['name'] ?? ($_SESSION['user'] ?? '');
    if ($do === 'pay' || $do === 'settle') {
        $total = ce_unpaid_total($idnum);
        $amt = $do === 'settle' ? $total : round((float)str_replace(',', '', (string)($_POST['amount'] ?? '0')), 2);
        [$ok, $msg] = ce_pay($idnum, $amt, $by);
        flash(($ok ? '' : 'Not done: ') . $msg);
    } elseif ($do === 'waive') {
        [$ok, $msg] = ce_waive((int)($_POST['fine'] ?? 0), $by);
        flash(($ok ? '' : 'Not done: ') . $msg);
    }
    redirect('balance.php?' . http_build_query(['idnum' => $idnum]));
}

$client = client_by_id($idnum);
$matches = ($idnum !== '' && !$client) ? ce_find_clients($idnum) : [];
include __DIR__ . '/includes/header.php'; ?>
<h1>Balance &amp; payments</h1>
<p class="tabs no-print">
  <a class="<?= $t === 'balance' ? 'on' : '' ?>" href="balance.php?idnum=<?= e(urlencode($idnum)) ?>">Balance</a>
  <?php if (can_edit_records()): ?><a class="<?= $t === 'payments' ? 'on' : '' ?>" href="balance.php?t=payments">Payments</a><?php endif; ?>
  <a class="<?= $t === 'estimate' ? 'on' : '' ?>" href="balance.php?t=estimate&idnum=<?= e(urlencode($idnum)) ?>">Estimate</a></p>

<?php if ($t === 'balance'): ?>
  <form class="toolbar no-print" method="get"><input type="hidden" name="t" value="balance">
    <input type="search" name="idnum" value="<?= e($idnum) ?>" placeholder="Borrower ID number (or part of the name)" autofocus><button class="btn primary">Find borrower</button>
    <?php if ($idnum !== ''): ?><a class="btn ghost" href="balance.php">Everyone with a balance</a><?php endif; ?></form>

  <?php if ($client):
      $rows = ce_unpaid_rows($idnum); $total = ce_unpaid_total($idnum);
      $st = $pdo->prepare('SELECT * FROM Cashier WHERE ID_number = ? ORDER BY DatePayment DESC, ID DESC LIMIT 15'); $st->execute([$idnum]); $paid = $st->fetchAll();
      $st = $pdo->prepare("SELECT * FROM fine_balance WHERE ID_Number = ? AND Status <> 'Unpaid' ORDER BY Settled DESC, ID DESC LIMIT 10"); $st->execute([$idnum]); $past = $st->fetchAll(); ?>
    <div class="card">
      <h2><?= e($client['Name']) ?> <small class="muted">· ID <?= e($client['IDnum']) ?></small></h2>
      <p class="muted" style="margin:0"><?= e(implode(' · ', array_filter([$client['Grade_level'] ?? null, $client['User_class'], $client['Degree_Course'], $client['Year_Level'], $client['Section'] ?? null]))) ?>
        · <a href="circulation.php?idnum=<?= e(urlencode($idnum)) ?>">Circulation</a> · <a href="history.php?idnum=<?= e(urlencode($idnum)) ?>">History</a> · <a href="balance.php?t=estimate&idnum=<?= e(urlencode($idnum)) ?>">Estimate</a></p>
      <p style="font-size:22px;margin:10px 0 0"><strong class="<?= $total > 0 ? 'danger' : '' ?>"><?= e(money($total)) ?></strong> <small class="muted"><?= $total > 0 ? 'unpaid' : 'nothing owed' ?></small></p>
    </div>

    <?php if ($rows): ?>
    <div class="table-wrap"><table class="data"><thead><tr><th>Assessed</th><th>Accession</th><th>Title</th><th>Days late</th><th>Fine</th><th>Paid</th><th>Owing</th><?php if (can_edit_records()): ?><th class="no-print"></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr>
      <td><?= e(fdate($r['Assessed'])) ?></td><td><?= e($r['Accession']) ?></td><td><?= e($r['Title']) ?></td><td><?= (int)$r['Days_late'] ?></td>
      <td><?= e(money($r['Amount'])) ?></td><td><?= $r['Paid'] > 0 ? e(money($r['Paid'])) : '' ?></td><td><strong><?= e(money($r['Amount'] - $r['Paid'])) ?></strong></td>
      <?php if (can_edit_records()): ?><td class="no-print row-actions"><form method="post" data-confirm="Waive this fine? The borrower will no longer owe it."><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="idnum" value="<?= e($idnum) ?>"><input type="hidden" name="fine" value="<?= (int)$r['ID'] ?>"><button class="link danger" name="do" value="waive">Waive</button></form></td><?php endif; ?>
    </tr><?php endforeach; ?></tbody></table></div>

    <form method="post" class="card form no-print" autocomplete="off">
      <h2 style="margin:0">Take a payment</h2>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="idnum" value="<?= e($idnum) ?>">
      <label><span>Amount received (<?= e(circ_cfg()['currency']) ?>)</span><input type="number" name="amount" step="0.01" min="0.01" max="<?= e(number_format($total, 2, '.', '')) ?>" value="<?= e(number_format($total, 2, '.', '')) ?>" required></label>
      <p class="muted" style="margin:0">A part payment clears the oldest fines first. A receipt line is added to the payments list.</p>
      <div class="actions"><button class="btn primary" name="do" value="pay">Record payment</button>
        <button class="btn" name="do" value="settle" formnovalidate>Settle in full (<?= e(money($total)) ?>)</button></div>
    </form>
    <?php else: ?><p class="muted">No unpaid fines.</p><?php endif; ?>

    <?php if ($past): ?><h2 style="margin-top:24px">Settled fines</h2>
    <div class="table-wrap"><table class="data"><thead><tr><th>Assessed</th><th>Title</th><th>Fine</th><th>Paid</th><th>Result</th><th>Settled</th></tr></thead><tbody>
    <?php foreach ($past as $r): ?><tr><td><?= e(fdate($r['Assessed'])) ?></td><td><?= e($r['Title']) ?></td><td><?= e(money($r['Amount'])) ?></td><td><?= e(money($r['Paid'])) ?></td><td><?= e($r['Status']) ?></td><td><?= e(fdate($r['Settled'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

    <h2 style="margin-top:24px">Payments received</h2>
    <div class="table-wrap"><table class="data"><thead><tr><th>Date</th><th>Amount</th><th>Note</th></tr></thead><tbody>
    <?php foreach ($paid as $r): ?><tr><td><?= e(fdate($r['DatePayment'])) ?></td><td><?= e(money($r['Amount'])) ?></td><td><?= e($r['Note'] ?? '') ?></td></tr>
    <?php endforeach; if (!$paid): ?><tr><td colspan="3" class="muted">No payments yet.</td></tr><?php endif; ?></tbody></table></div>

  <?php elseif ($idnum !== ''): ?>
    <?php if ($matches): ?><p class="muted">No exact ID match. Did you mean:</p>
      <div class="table-wrap"><table class="data"><thead><tr><th>ID number</th><th>Name</th><th>Class</th></tr></thead><tbody>
      <?php foreach ($matches as $m): ?><tr><td><a href="balance.php?idnum=<?= e(urlencode($m['IDnum'])) ?>"><?= e($m['IDnum']) ?></a></td><td><?= e($m['Name']) ?></td><td><?= e($m['User_class']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php else: ?><p>No borrower found.</p><?php endif; ?>

  <?php else:
      $args = []; $lw = ce_lib_where('f.Library', $args, trim($_GET['lib'] ?? ''));
      $st = $pdo->prepare("SELECT f.ID_Number, c.Name, c.User_class, COUNT(*) AS n, SUM(f.Amount - f.Paid) AS owing, MIN(f.Assessed) AS since
          FROM fine_balance f LEFT JOIN Client c ON c.IDnum = f.ID_Number WHERE f.Status = 'Unpaid'$lw
          GROUP BY f.ID_Number, c.Name, c.User_class ORDER BY owing DESC LIMIT 300");
      $st->execute($args); $owers = $st->fetchAll(); $sum = 0; foreach ($owers as $o) $sum += (float)$o['owing']; ?>
    <form class="toolbar no-print" method="get"><input type="hidden" name="t" value="balance"><?= ce_lib_picker(trim($_GET['lib'] ?? '')) ?>
      <?php if (lib_all()): ?><button class="btn">Filter</button><?php endif; ?><button type="button" class="btn ghost" data-print>Print</button></form>
    <p class="muted"><?= count($owers) ?> borrower<?= count($owers) === 1 ? '' : 's' ?> with an unpaid fine · total owing <strong><?= e(money($sum)) ?></strong></p>
    <div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Borrower</th><th>Class</th><th>Fines</th><th>Since</th><th>Owing</th></tr></thead><tbody>
    <?php foreach ($owers as $o): ?><tr><td><a href="balance.php?idnum=<?= e(urlencode($o['ID_Number'])) ?>"><?= e($o['Name'] ?: $o['ID_Number']) ?></a> <small class="muted">(<?= e($o['ID_Number']) ?>)</small></td>
      <td><?= e($o['User_class']) ?></td><td><?= (int)$o['n'] ?></td><td><?= e(fdate($o['since'])) ?></td><td><strong><?= e(money($o['owing'])) ?></strong></td></tr>
    <?php endforeach; if (!$owers): ?><tr><td colspan="5" class="muted">Nobody owes a fine.</td></tr><?php endif; ?></tbody></table></div>
    <p class="muted no-print">Fines that were not collected before this screen was installed are not tracked here. From now on, an uncollected fine at return becomes a balance.</p>
  <?php endif; ?>

<?php elseif ($t === 'payments'):
    $from = ce_date($_GET['from'] ?? '', date('Y-m-01')); $to = ce_date($_GET['to'] ?? '', date('Y-m-d')); $q = trim($_GET['q'] ?? '');
    $where = 'DatePayment >= :f AND DatePayment <= :t'; $args = ['f' => $from, 't' => $to];
    if ($q !== '') { $where .= ' AND (ID_number LIKE :q1 OR Name LIKE :q2)'; $args['q1'] = $args['q2'] = "%$q%"; }
    $st = $pdo->prepare("SELECT * FROM Cashier WHERE $where ORDER BY DatePayment DESC, ID DESC LIMIT 500"); $st->execute($args); $pay = $st->fetchAll();
    $sum = 0; foreach ($pay as $p) $sum += (float)$p['Amount']; ?>
  <form class="toolbar no-print" method="get"><input type="hidden" name="t" value="payments">
    <label class="inline">From <input type="date" name="from" value="<?= e($from) ?>"></label><label class="inline">To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Borrower ID or name"><button class="btn">List</button><button type="button" class="btn ghost" data-print>Print</button></form>
  <p class="muted"><?= count($pay) ?> payment<?= count($pay) === 1 ? '' : 's' ?> from <?= e(fdate($from)) ?> to <?= e(fdate($to)) ?> · total <strong><?= e(money($sum)) ?></strong><?= count($pay) === 500 ? ' (showing the latest 500)' : '' ?></p>
  <div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Date</th><th>Borrower</th><th>Amount</th><th>Note</th></tr></thead><tbody>
  <?php foreach ($pay as $p): ?><tr><td><?= e(fdate($p['DatePayment'])) ?></td><td><a href="balance.php?idnum=<?= e(urlencode($p['ID_number'])) ?>"><?= e($p['Name'] ?: $p['ID_number']) ?></a> <small class="muted">(<?= e($p['ID_number']) ?>)</small></td>
    <td><?= e(money($p['Amount'])) ?></td><td><?= e($p['Note'] ?? '') ?></td></tr>
  <?php endforeach; if (!$pay): ?><tr><td colspan="4" class="muted">No payments in this range.</td></tr><?php endif; ?></tbody></table></div>

<?php else: // estimate
    $asOf = ce_date($_GET['on'] ?? '', date('Y-m-d')); $acc = trim($_GET['acc'] ?? '');
    if ($acc !== '' && $idnum === '' && ($ol = open_loan($acc))) { $idnum = (string)$ol['ID_Number']; }
    $client = client_by_id($idnum);
    $matches = ($idnum !== '' && !$client) ? ce_find_clients($idnum) : []; ?>
  <form class="toolbar no-print" method="get"><input type="hidden" name="t" value="estimate">
    <input type="search" name="idnum" value="<?= e($idnum) ?>" placeholder="Borrower ID number (or part of the name)" autofocus>
    <input type="text" name="acc" value="<?= e($acc) ?>" placeholder="Accession (optional)" style="max-width:190px">
    <label class="inline">If returned on <input type="date" name="on" value="<?= e($asOf) ?>"></label>
    <button class="btn primary">Estimate</button><button type="button" class="btn ghost" data-print>Print</button></form>
  <?php if ($client):
      $st = $pdo->prepare('SELECT * FROM Borrower WHERE ID_Number = ? AND Date_returned IS NULL ORDER BY Due_date'); $st->execute([$idnum]); $loans = $st->fetchAll();
      if ($acc !== '') $loans = array_values(array_filter($loans, function ($l) use ($acc) { return strcasecmp($l['Accession_number'], $acc) === 0; }));
      $tot = 0; $owing = ce_unpaid_total($idnum); ?>
    <div class="card"><h2><?= e($client['Name']) ?> <small class="muted">· ID <?= e($client['IDnum']) ?> · <?= e($client['User_class']) ?></small></h2>
      <p class="muted" style="margin:0">Fine estimate if everything below is returned on <strong><?= e(date('D, M j, Y', strtotime($asOf))) ?></strong>. Closed days (Sundays and holidays) are not counted.</p></div>
    <div class="table-wrap"><table class="data"><thead><tr><th>Accession</th><th>Title</th><th>Due</th><th>Days late</th><th>Rate / day</th><th>Fine</th></tr></thead><tbody>
    <?php foreach ($loans as $l): $c = ce_loan_calc($l, $client['User_class'], $asOf); $tot += $c['fine']; ?>
      <tr><td><?= e($l['Accession_number']) ?></td><td><?= e($l['Title']) ?></td><td class="<?= $c['late'] ? 'late' : '' ?>"><?= e(fdate($l['Due_date'])) ?></td>
        <td><?= $c['lost'] ? 'Lost' : ($c['late'] ?: '') ?></td><td><?= $c['lost'] ? '' : e(money($c['rate'])) ?></td><td><?= $c['fine'] > 0 ? e(money($c['fine'])) : '' ?></td></tr>
    <?php endforeach; if (!$loans): ?><tr><td colspan="6" class="muted">Nothing on loan.</td></tr><?php endif; ?></tbody></table></div>
    <p>Estimated fine on these loans: <strong><?= e(money($tot)) ?></strong><?php if ($owing > 0): ?> · already owing from earlier fines: <strong><?= e(money($owing)) ?></strong> · total to pay: <strong><?= e(money($tot + $owing)) ?></strong><?php endif; ?></p>
    <p class="muted no-print">An estimate only; nothing is saved. Lost items have no per-day fine.</p>
  <?php elseif ($idnum !== ''): ?>
    <?php if ($matches): ?><p class="muted">No exact ID match. Did you mean:</p>
      <div class="table-wrap"><table class="data"><thead><tr><th>ID number</th><th>Name</th><th>Class</th></tr></thead><tbody>
      <?php foreach ($matches as $m): ?><tr><td><a href="balance.php?t=estimate&idnum=<?= e(urlencode($m['IDnum'])) ?>"><?= e($m['IDnum']) ?></a></td><td><?= e($m['Name']) ?></td><td><?= e($m['User_class']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php else: ?><p>No borrower found.</p><?php endif; ?>
  <?php else: ?><p class="muted">Enter a borrower (or an accession number that is on loan) and a return date to see the fine it would carry.</p><?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
