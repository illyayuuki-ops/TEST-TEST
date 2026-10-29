<?php
// Daily lists: items due on a day, items lent on a day, and everything overdue (with printable notices).
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db();
$pageTitle = 'Due / out today';
$f = $_GET['f'] ?? 'due'; if (!in_array($f, ['due', 'out', 'overdue'], true)) $f = 'due';
$today = date('Y-m-d');
$d = ce_date($_GET['d'] ?? '', $today);
$lf = trim($_GET['lib'] ?? '');

// One query for the three tabs. Lost items are not "due".
function dl_rows(PDO $pdo, string $f, string $d, string $today, string $lf): array {
    $args = []; $w = '1=1';
    if ($f === 'due')      { $w = "b.Date_returned IS NULL AND b.Status <> 'Lost' AND DATE(b.Due_date) = :d"; $args['d'] = $d; }
    elseif ($f === 'out')  { $w = 'DATE(b.Date_Borrowed) = :d'; $args['d'] = $d; }
    else                   { $w = "b.Date_returned IS NULL AND b.Status <> 'Lost' AND DATE(b.Due_date) < :d"; $args['d'] = $today; }
    $w .= ce_lib_where('b.Library', $args, $lf);
    $order = $f === 'overdue' ? 'b.Due_date ASC, b.ID' : 'b.Library, b.ID';
    $st = $pdo->prepare("SELECT b.*, c.Name, c.User_class FROM Borrower b LEFT JOIN Client c ON c.IDnum = b.ID_Number WHERE $w ORDER BY $order LIMIT 1000");
    $st->execute($args);
    return $st->fetchAll();
}
$rows = dl_rows($pdo, $f, $d, $today, $lf);

// ---------- print all notices: one page per borrower ----------
if ($f === 'overdue' && isset($_GET['notices'])) {
    $by = [];
    foreach ($rows as $r) $by[$r['ID_Number']][] = $r;
    [$body, $sign] = ce_notice_text();
    $pageTitle = 'Overdue notices';
    include __DIR__ . '/includes/header.php'; ?>
    <p class="no-print"><a href="daily.php?f=overdue<?= $lf !== '' ? '&lib=' . e(urlencode($lf)) : '' ?>">‹ Overdue list</a> · <button class="btn" data-print>Print <?= count($by) ?> notice<?= count($by) === 1 ? '' : 's' ?></button></p>
    <?php foreach ($by as $id => $items): $name = $items[0]['Name'] ?: $id; $cls = $items[0]['User_class']; $tot = 0; $owing = ce_unpaid_total((string)$id); ?>
      <div class="card notice-page" style="max-width:720px">
        <h2><?= e($CONFIG['library_name']) ?> · Overdue notice</h2>
        <p><?= e(date('F j, Y')) ?></p>
        <p>To: <strong><?= e($name) ?></strong> (ID <?= e($id) ?>)</p>
        <p><?= e($body) ?></p>
        <table class="data"><thead><tr><th>Accession</th><th>Title</th><th>Due</th><th>Days late</th><th>Fine</th></tr></thead><tbody>
        <?php foreach ($items as $r): $c = ce_loan_calc($r, $cls); $tot += $c['fine']; ?>
          <tr><td><?= e($r['Accession_number']) ?></td><td><?= e($r['Title']) ?></td><td><?= e(fdate($r['Due_date'])) ?></td><td><?= $c['late'] ?></td><td><?= e(money($c['fine'])) ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <p><strong>Total fine so far: <?= e(money($tot)) ?></strong><?= $owing > 0 ? ' · Earlier unpaid fines: <strong>' . e(money($owing)) . '</strong>' : '' ?></p>
        <?php if ($sign): ?><p style="margin-top:32px"><?= e($sign) ?></p><?php endif; ?>
      </div>
    <?php endforeach; if (!$by): ?><p class="muted">Nothing is overdue.</p><?php endif;
    include __DIR__ . '/includes/footer.php'; exit;
}

// tab counts
$cnt = [];
foreach (['due', 'out', 'overdue'] as $k) $cnt[$k] = count(dl_rows($pdo, $k, $d, $today, $lf));
$tabs = ['due' => 'Due', 'out' => 'Out', 'overdue' => 'Overdue'];
$isToday = $d === $today;
$qs = function ($k) use ($d, $lf) { return 'daily.php?' . http_build_query(array_filter(['f' => $k, 'd' => $d, 'lib' => $lf], function ($v) { return $v !== ''; })); };
include __DIR__ . '/includes/header.php'; ?>
<h1><?= $f === 'overdue' ? 'Overdue items' : ($f === 'due' ? 'Due ' : 'Out ') . ($isToday ? 'today' : 'on ' . e(fdate($d))) ?></h1>
<p class="tabs no-print"><?php foreach ($tabs as $k => $label): ?><a class="<?= $k === $f ? 'on' : '' ?>" href="<?= e($qs($k)) ?>"><?= e($label . ($k !== 'overdue' && $isToday ? ' today' : '')) ?> (<?= $cnt[$k] ?>)</a><?php endforeach; ?></p>
<form class="toolbar no-print" method="get"><input type="hidden" name="f" value="<?= e($f) ?>">
  <?php if ($f !== 'overdue'): ?><label class="inline">Date <input type="date" name="d" value="<?= e($d) ?>"></label><?php endif; ?>
  <?= ce_lib_picker($lf) ?>
  <button class="btn">List</button>
  <?php if ($f !== 'overdue' && !$isToday): ?><a class="btn ghost" href="<?= e('daily.php?f=' . $f) ?>">Today</a><?php endif; ?>
  <button type="button" class="btn ghost" data-print>Print</button>
  <?php if ($f === 'overdue' && $rows): ?><a class="btn" href="<?= e($qs('overdue') . '&notices=1') ?>">Print all notices</a><?php endif; ?></form>
<p class="muted"><?= count($rows) ?> item<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 1000 ? ' (showing the first 1000)' : '' ?></p>
<div class="table-wrap"><table class="data" data-sortable><thead><tr>
  <th>Borrower</th><th>Class</th><th>Accession</th><th>Title</th><th>Borrowed</th><th>Due</th>
  <?php if ($f === 'out'): ?><th>Status</th><?php elseif ($f === 'overdue'): ?><th>Days late</th><th>Fine</th><?php endif; ?>
  <?php if (lib_all()): ?><th>Library</th><?php endif; ?><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r): $c = ce_loan_calc($r, $r['User_class']); ?>
  <tr<?= ($f === 'overdue') ? ' class="overdue"' : '' ?>>
    <td><a href="circulation.php?idnum=<?= e(urlencode($r['ID_Number'])) ?>"><?= e($r['Name'] ?: $r['ID_Number']) ?></a> <small class="muted">(<?= e($r['ID_Number']) ?>)</small></td>
    <td><?= e($r['User_class']) ?></td><td><?= e($r['Accession_number']) ?></td><td><?= e($r['Title']) ?></td>
    <td><?= e(fdate($r['Date_Borrowed'])) ?></td><td><?= e(fdate($r['Due_date'])) ?></td>
    <?php if ($f === 'out'): ?><td><?= e($c['lost'] ? 'Lost' : ($c['open'] ? 'Out' : 'Returned ' . fdate($r['Date_returned']))) ?></td>
    <?php elseif ($f === 'overdue'): ?><td><?= $c['late'] ?></td><td><?= e(money($c['fine'])) ?></td><?php endif; ?>
    <?php if (lib_all()): ?><td><?= e($r['Library']) ?></td><?php endif; ?>
    <td class="no-print row-actions"><?php if ($f === 'overdue'): ?><a href="loans.php?notice=<?= e(urlencode($r['ID_Number'])) ?>">Notice</a><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="10" class="muted">Nothing here.</td></tr><?php endif; ?></tbody></table></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
