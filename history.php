<?php
// History: every loan of one borrower, or every borrower of one item.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db();
$pageTitle = 'History';
$t = ($_GET['t'] ?? 'borrower') === 'item' ? 'item' : 'borrower';
$idnum = trim($_GET['idnum'] ?? ''); $acc = trim($_GET['acc'] ?? '');
$from = ce_date($_GET['from'] ?? '', ''); $to = ce_date($_GET['to'] ?? '', '');
include __DIR__ . '/includes/header.php'; ?>
<h1>History</h1>
<p class="tabs no-print"><a class="<?= $t === 'borrower' ? 'on' : '' ?>" href="history.php?idnum=<?= e(urlencode($idnum)) ?>">Borrower</a><a class="<?= $t === 'item' ? 'on' : '' ?>" href="history.php?t=item&acc=<?= e(urlencode($acc)) ?>">Item</a></p>

<?php if ($t === 'borrower'):
    $client = client_by_id($idnum);
    $matches = ($idnum !== '' && !$client) ? ce_find_clients($idnum) : []; ?>
  <form class="toolbar no-print" method="get">
    <input type="search" name="idnum" value="<?= e($idnum) ?>" placeholder="Borrower ID number (or part of the name)" autofocus>
    <label class="inline">From <input type="date" name="from" value="<?= e($from) ?>"></label><label class="inline">To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn primary">Show history</button><?php if ($client): ?><button type="button" class="btn ghost" data-print>Print</button><?php endif; ?></form>
  <?php if ($client):
      $args = ['id' => $idnum]; $w = 'ID_Number = :id';
      if ($from !== '') { $w .= ' AND DATE(Date_Borrowed) >= :f'; $args['f'] = $from; }
      if ($to !== '')   { $w .= ' AND DATE(Date_Borrowed) <= :t'; $args['t'] = $to; }
      $w .= ce_lib_where('Library', $args);
      $st = $pdo->prepare("SELECT * FROM Borrower WHERE $w ORDER BY Date_Borrowed DESC, ID DESC LIMIT 500"); $st->execute($args); $loans = $st->fetchAll();
      $map = ce_loan_fine_map($idnum); $n = count($loans); $out = 0; $lateSum = 0; $fines = 0; $lost = 0;
      $calc = [];
      foreach ($loans as $i => $l) { $c = ce_loan_calc($l, $client['User_class']); $calc[$i] = $c; if ($c['open'] && !$c['lost']) $out++; if ($c['lost']) $lost++; $lateSum += $c['late']; $fines += $c['fine']; }
      $owing = ce_unpaid_total($idnum); ?>
    <div class="card">
      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <?php if ($u = ce_photo_url($idnum)): ?><img class="photo" src="<?= e($u) ?>" alt="Photo of <?= e($client['Name']) ?>"><?php endif; ?>
        <div><h2 style="margin:0"><?= e($client['Name']) ?> <small class="muted">· ID <?= e($client['IDnum']) ?></small></h2>
          <p class="muted" style="margin:0"><?= e(implode(' · ', array_filter([$client['Grade_level'] ?? null, $client['User_class'], $client['Degree_Course'], $client['Year_Level'], $client['Section'] ?? null]))) ?>
            <span class="no-print">· <a href="circulation.php?idnum=<?= e(urlencode($idnum)) ?>">Circulation</a> · <a href="balance.php?idnum=<?= e(urlencode($idnum)) ?>">Balance</a></span></p>
          <p style="margin:6px 0 0"><strong><?= $n ?></strong> loan<?= $n === 1 ? '' : 's' ?> · <?= $out ?> out now<?= $lost ? " · $lost lost" : '' ?> · <?= $lateSum ?> late day<?= $lateSum === 1 ? '' : 's' ?> · fines <?= e(money($fines)) ?><?= $owing > 0 ? ' · <strong class="danger">owing ' . e(money($owing)) . '</strong>' : '' ?>
            <?= ($from || $to) ? ' · <span class="muted">' . e(($from ? fdate($from) : 'start') . ' to ' . ($to ? fdate($to) : 'today')) . '</span>' : '' ?></p></div>
      </div></div>
    <div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Accession</th><th>Title</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Days late</th><th>Fine</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($loans as $i => $l): $c = $calc[$i]; ?>
      <tr class="<?= ($c['open'] && $c['late'] && !$c['lost']) ? 'overdue' : '' ?>"><td><a href="history.php?t=item&acc=<?= e(urlencode($l['Accession_number'])) ?>"><?= e($l['Accession_number']) ?></a></td><td><?= e($l['Title']) ?></td>
        <td><?= e(fdate($l['Date_Borrowed'])) ?></td><td><?= e(fdate($l['Due_date'])) ?></td><td><?= e(fdate($l['Date_returned'])) ?></td>
        <td><?= $c['late'] ?: '' ?></td><td><?= $c['fine'] > 0 ? e(money($c['fine'])) : '' ?></td><td><?= e(ce_loan_state($l, $c, $map)) ?></td></tr>
    <?php endforeach; if (!$loans): ?><tr><td colspan="8" class="muted">No loans found.</td></tr><?php endif; ?></tbody></table></div>
    <?php if ($n === 500): ?><p class="muted">Showing the latest 500 loans. Use the date range to see older ones.</p><?php endif; ?>
    <p class="muted no-print">Fines that were not collected before the balance screen was installed show as "fine collected".</p>
  <?php elseif ($idnum !== ''): ?>
    <?php if ($matches): ?><p class="muted">No exact ID match. Did you mean:</p>
      <div class="table-wrap"><table class="data"><thead><tr><th>ID number</th><th>Name</th><th>Class</th></tr></thead><tbody>
      <?php foreach ($matches as $m): ?><tr><td><a href="history.php?idnum=<?= e(urlencode($m['IDnum'])) ?>"><?= e($m['IDnum']) ?></a></td><td><?= e($m['Name']) ?></td><td><?= e($m['User_class']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php else: ?><p>No borrower found.</p><?php endif; ?>
  <?php else: ?><p class="muted">Type a borrower's ID number (or part of the name) to see everything they have borrowed.</p><?php endif; ?>

<?php else: // item
    $item = $acc !== '' ? find_item($acc) : null; ?>
  <form class="toolbar no-print" method="get"><input type="hidden" name="t" value="item">
    <input type="search" name="acc" value="<?= e($acc) ?>" placeholder="Accession number (e.g. COL-000001, SER-12)" autofocus>
    <button class="btn primary">Show history</button><?php if ($acc !== ''): ?><button type="button" class="btn ghost" data-print>Print</button><?php endif; ?></form>
  <?php if ($acc !== ''):
      $args = ['a' => $acc]; $lw = ce_lib_where('b.Library', $args);
      $st = $pdo->prepare("SELECT b.*, c.Name, c.User_class FROM Borrower b LEFT JOIN Client c ON c.IDnum = b.ID_Number WHERE b.Accession_number = :a$lw ORDER BY b.Date_Borrowed DESC, b.ID DESC LIMIT 500");
      $st->execute($args); $loans = $st->fetchAll();
      $hidden = false;
      if ($item && !lib_all() && stripos((string)$item['Location'], user_library()) !== 0) { $hidden = true; $loans = []; }
      $now = open_loan($acc); $res = $item ? active_reservation($acc) : null;
      if ($hidden) $state = 'Kept in another library';
      elseif ($now) $state = $now['Status'] === 'Lost' ? 'Lost' : 'On loan, due ' . fdate($now['Due_date']);
      elseif ($res) $state = (($res['Kind'] ?? 'Reserve') === 'Hold' ? 'Held for ' : 'Reserved for ') . $res['Name'] . ' until ' . fdate($res['DateExpired']);
      else $state = $item ? 'Available' : 'Not in the catalog'; ?>
    <div class="card"><h2 style="margin:0"><?= $item ? e($item['Title']) : 'No catalog record' ?> <small class="muted">· <?= e($acc) ?></small></h2>
      <p class="muted" style="margin:4px 0 0"><?= $item ? e(implode(' · ', array_filter([$item['Call_Number'], $item['Location'], $item['Kind']]))) : 'This accession number is not in the catalog (it may have been deleted); loans recorded against it are shown below.' ?></p>
      <p style="margin:8px 0 0">Now: <strong><?= e($state) ?></strong> · <?= count($loans) ?> loan<?= count($loans) === 1 ? '' : 's' ?> in all</p></div>
    <div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Borrower</th><th>Class</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Days late</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($loans as $l): $c = ce_loan_calc($l, $l['User_class']); ?>
      <tr><td><a href="history.php?idnum=<?= e(urlencode($l['ID_Number'])) ?>"><?= e($l['Name'] ?: $l['ID_Number']) ?></a> <small class="muted">(<?= e($l['ID_Number']) ?>)</small></td><td><?= e($l['User_class']) ?></td>
        <td><?= e(fdate($l['Date_Borrowed'])) ?></td><td><?= e(fdate($l['Due_date'])) ?></td><td><?= e(fdate($l['Date_returned'])) ?></td><td><?= $c['late'] ?: '' ?></td>
        <td><?= e($c['lost'] ? 'Lost' : ($c['open'] ? ($c['late'] ? 'Overdue' : 'On loan') : 'Returned')) ?></td></tr>
    <?php endforeach; if (!$loans): ?><tr><td colspan="7" class="muted"><?= $hidden ? 'This item belongs to another library.' : 'This item has never been borrowed.' ?></td></tr><?php endif; ?></tbody></table></div>
  <?php else: ?><p class="muted">Type or scan an accession number to see who has borrowed the item.</p><?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
