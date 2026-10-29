<?php
// Transaction Monitoring: lockers, internet computers and discussion rooms. Time in, time due, penalty, statistics.
//   ?v=monitor (default)   ?v=stats   ?v=items (admin / librarian: the list of items and the penalty rates)
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
require __DIR__ . '/includes/txn.php';
$pdo = db();
$pageTitle = 'Transaction monitoring';
$base = 'transactions.php';

try { $pdo->query('SELECT 1 FROM txn_log LIMIT 1'); $pdo->query('SELECT 1 FROM txn_item LIMIT 1'); $pdo->query('SELECT 1 FROM txn_rate LIMIT 1'); }
catch (PDOException $ex) {
    include __DIR__ . '/includes/header.php';
    echo '<h1>Transaction monitoring</h1><div class="card"><p>The transaction tables are missing. Import <code>04_transaction_monitoring.sql</code> in phpMyAdmin, then reload this page.</p></div>';
    include __DIR__ . '/includes/footer.php'; exit;
}

$libs = libraries();
$v = $_GET['v'] ?? 'monitor';
if (!in_array($v, ['monitor', 'stats', 'items'], true)) $v = 'monitor';
if ($v === 'items' && !can_edit_records()) $v = 'monitor';
$rates = txn_rates();
$formError = '';
$keep = ['idnum' => '', 'item' => 0, 'hours' => (int)$rates['Locker']['Default_hours']];
$okDate = fn($d) => ($x = DateTime::createFromFormat('Y-m-d', (string)$d)) && $x->format('Y-m-d') === $d;
$back = fn(array $extra = []) => $base . '?' . http_build_query($extra);

// ---------- actions (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $f = $_POST['f'] ?? 'current';

    if ($do === 'checkout') {
        $keep = ['idnum' => trim($_POST['idnum'] ?? ''), 'item' => (int)($_POST['item'] ?? 0), 'hours' => (int)($_POST['hours'] ?? 1)];
        if ($keep['idnum'] === '') $formError = 'Enter or scan the borrower ID.';
        elseif (!$keep['item']) $formError = 'Pick a locker, computer or room.';
        else {
            [$ok, $msg] = txn_checkout($keep['idnum'], $keep['item'], $keep['hours']);
            if ($ok) { flash($msg); redirect($base); }
            $formError = $msg;
        }
    } elseif ($do === 'checkin') {
        $row = txn_row((int)($_POST['id'] ?? 0));
        if (!$row) flash('Not done: record not found.'); else { [, $msg] = txn_checkin($row, !empty($_POST['paid'])); flash($msg); }
        redirect($back(['f' => $f]));
    } elseif ($do === 'paid') {
        $row = txn_row((int)($_POST['id'] ?? 0));
        if ($row && $row['Status'] === 'Returned' && (float)$row['Penalty'] > 0) { $pdo->prepare('UPDATE txn_log SET Paid = 1 WHERE ID = ?')->execute([$row['ID']]); flash('Penalty marked as paid.'); }
        else flash('Not done: nothing to mark as paid.');
        redirect($back(['f' => $f]));
    } elseif ($do === 'delete_log' && can_edit_records()) {
        $row = txn_row((int)($_POST['id'] ?? 0));
        if ($row) { $pdo->prepare('DELETE FROM txn_log WHERE ID = ?')->execute([$row['ID']]); flash('Record deleted.'); } else flash('Not done: record not found.');
        redirect($back(['f' => $f]));
    } elseif (in_array($do, ['add_item', 'toggle_item', 'delete_item', 'save_rates'], true) && can_edit_records()) {
        if ($do === 'add_item') {
            $type = $_POST['type'] ?? ''; $lib = lib_all() ? trim($_POST['library'] ?? '') : user_library();
            $names = [];
            $single = trim($_POST['name'] ?? '');
            if ($single !== '') $names[] = $single;
            else {
                $pre = trim($_POST['prefix'] ?? ''); $a = (int)($_POST['from'] ?? 0); $b = (int)($_POST['to'] ?? 0);
                if ($a > 0 && $b >= $a && $b - $a < 200) for ($i = $a; $i <= $b; $i++) $names[] = ($pre !== '' ? $pre . ' ' : '') . $i;
            }
            if (!isset(TXN_TYPES[$type])) flash('Not done: pick a type.');
            elseif (!in_array($lib, $libs, true)) flash('Not done: pick a library.');
            elseif (!$names) flash('Not done: enter a name, or a number range (up to 200).');
            else {
                $ins = $pdo->prepare('INSERT IGNORE INTO txn_item (Type, Name, Library, Active) VALUES (?,?,?,1)'); $added = 0;
                foreach ($names as $n) { if (strlen($n) <= 40) { $ins->execute([$type, $n, $lib]); $added += $ins->rowCount(); } }
                flash($added . ' item' . ($added === 1 ? '' : 's') . ' added' . ($added < count($names) ? ' (the rest already existed or were too long)' : '') . '.');
            }
        } elseif ($do === 'toggle_item') {
            $it = txn_item_row((int)($_POST['id'] ?? 0));
            if ($it) { $pdo->prepare('UPDATE txn_item SET Active = 1 - Active WHERE ID = ?')->execute([$it['ID']]); flash('Item ' . ($it['Active'] ? 'turned off (hidden from check-out).' : 'turned on.')); }
        } elseif ($do === 'delete_item') {
            $it = txn_item_row((int)($_POST['id'] ?? 0));
            $used = $it ? $pdo->prepare('SELECT COUNT(*) FROM txn_log WHERE ItemID = ?') : null;
            if ($it) { $used->execute([$it['ID']]); }
            if (!$it) flash('Not done: item not found.');
            elseif ((int)$used->fetchColumn() > 0) flash('Not deleted: it has records. Turn it off instead.');
            else { $pdo->prepare('DELETE FROM txn_item WHERE ID = ?')->execute([$it['ID']]); flash('Item deleted.'); }
        } else { // save_rates
            $up = $pdo->prepare('INSERT INTO txn_rate (Type, Rate_per_hour, Default_hours) VALUES (?,?,?) ON DUPLICATE KEY UPDATE Rate_per_hour = VALUES(Rate_per_hour), Default_hours = VALUES(Default_hours)');
            $bad = false;
            foreach (array_keys(TXN_TYPES) as $t) {
                $r = $_POST['rate'][$t] ?? ''; $h = (int)($_POST['hours'][$t] ?? 0);
                if (!is_numeric($r) || (float)$r < 0 || (float)$r > 9999 || $h < 1 || $h > TXN_MAX_HOURS) { $bad = true; break; }
            }
            if ($bad) flash('Not saved: rates must be 0 or more, and default hours between 1 and ' . TXN_MAX_HOURS . '.');
            else { foreach (array_keys(TXN_TYPES) as $t) $up->execute([$t, round((float)$_POST['rate'][$t], 2), (int)$_POST['hours'][$t]]); flash('Rates saved. They apply to check-ins from now on.'); }
        }
        redirect($back(['v' => 'items']));
    }
}

$now = date('Y-m-d H:i:s');
$tabsHtml = function () use ($v, $base) {
    $t = ['monitor' => 'Monitor', 'stats' => 'Statistics'] + (can_edit_records() ? ['items' => 'Items & rates'] : []);
    echo '<p class="tabs no-print">'; foreach ($t as $k => $l) echo '<a class="' . ($k === $v ? 'on' : '') . '" href="' . e($base . '?v=' . $k) . '">' . e($l) . '</a>'; echo '</p>';
};
$libWhere = function (string $col, array &$args, string $lf = ''): string {
    global $libs;
    if (!lib_all()) { $args['lib'] = user_library(); return " AND $col = :lib"; }
    if (in_array($lf, $libs, true)) { $args['lib'] = $lf; return " AND $col = :lib"; }
    return '';
};

include __DIR__ . '/includes/header.php';
echo '<h1>Transaction monitoring</h1>';
$tabsHtml();

// =====================================================================  MONITOR
if ($v === 'monitor'):
    $f = $_GET['f'] ?? 'current';
    if (!in_array($f, ['current', 'overdue', 'unpaid', 'returned', 'all'], true)) $f = 'current';
    $q = trim($_GET['q'] ?? ''); $lf = $_GET['lib'] ?? ''; $tf = $_GET['t'] ?? '';
    $where = ['1=1']; $args = [];
    switch ($f) {
        case 'current':  $where[] = "Status = 'In use'"; break;
        case 'overdue':  $where[] = "Status = 'In use' AND Due_at < :now"; $args['now'] = $now; break;
        case 'unpaid':   $where[] = "Status = 'Returned' AND Penalty > 0 AND Paid = 0"; break;
        case 'returned': $where[] = "Status = 'Returned'"; break;
    }
    if (isset(TXN_TYPES[$tf])) { $where[] = 'Type = :t'; $args['t'] = $tf; }
    if ($q !== '') { $like = []; foreach (['Item_name', 'ID_Number', 'Borrower_name'] as $i => $c) { $like[] = "$c LIKE :q$i"; $args["q$i"] = "%$q%"; } $where[] = '(' . implode(' OR ', $like) . ')'; }
    $w = implode(' AND ', $where) . $libWhere('Library', $args, $lf);
    $order = in_array($f, ['returned', 'all', 'unpaid'], true) ? 'Time_in DESC' : 'Due_at ASC';
    $st = $pdo->prepare("SELECT * FROM txn_log WHERE $w ORDER BY $order LIMIT 300"); $st->execute($args); $rows = $st->fetchAll();

    $cargs = []; $lw = $libWhere('Library', $cargs);
    $cs = $pdo->prepare("SELECT COALESCE(SUM(Status='In use'),0) cur, COALESCE(SUM(Status='In use' AND Due_at < :now),0) od, COALESCE(SUM(Status='Returned' AND Penalty>0 AND Paid=0),0) unpaid FROM txn_log WHERE 1=1$lw");
    $cs->execute($cargs + ['now' => $now]); $c = $cs->fetch();
    $tabs = ['current' => ['In use', (int)$c['cur']], 'overdue' => ['Overdue', (int)$c['od']], 'unpaid' => ['Penalty unpaid', (int)$c['unpaid']], 'returned' => ['Returned', null], 'all' => ['All', null]];
    $free = txn_free_items();
    // "Who?": who is using a locker, computer or room right now.
    $who = trim($_GET['who'] ?? ''); $whoRows = [];
    if ($who !== '') {
        $wa = ['w' => "%$who%", 'w2' => "%$who%"]; $wl = $libWhere('Library', $wa);
        $ws = $pdo->prepare("SELECT * FROM txn_log WHERE Status = 'In use' AND (Item_name LIKE :w OR CONCAT(Type, ' ', Item_name) LIKE :w2)$wl ORDER BY Due_at"); $ws->execute($wa); $whoRows = $ws->fetchAll();
    }
    if ($formError) echo '<div class="err" role="alert">' . e($formError) . '</div>';
?>
<form method="get" class="toolbar no-print"><input type="hidden" name="v" value="monitor">
  <input type="search" name="who" value="<?= e($who) ?>" placeholder="Who has it? Type a locker, computer or room name…" aria-label="Who is using">
  <button class="btn">Who?</button></form>
<?php if ($who !== ''): ?><section class="card no-print"><h2>Who has "<?= e($who) ?>"?</h2>
<?php if ($whoRows): ?><div class="table-wrap"><table class="data"><thead><tr><th>Item</th><th>Borrower</th><th>Time in</th><th>Time due</th></tr></thead><tbody>
<?php foreach ($whoRows as $r): ?><tr><td><?= e(txn_title($r['Type'], $r['Item_name'])) ?></td><td><?= e($r['Borrower_name']) ?> <small class="muted">(<?= e($r['ID_Number']) ?>)</small></td><td><?= e(date('M j, g:i A', strtotime($r['Time_in']))) ?></td><td><?= e(date('M j, g:i A', strtotime($r['Due_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p class="muted">Nobody is using a matching item right now.</p><?php endif; ?></section><?php endif; ?>
<form method="post" class="card form no-print" autocomplete="off">
  <h2 style="margin:0">Check out</h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="checkout">
  <label><span>Borrower ID (scan or type)</span><input type="text" name="idnum" value="<?= e($keep['idnum']) ?>" maxlength="30" required autofocus></label>
  <label><span>Locker / computer / room</span>
    <select name="item" required><option value="">— choose —</option>
      <?php foreach ($free as $type => $items): ?><optgroup label="<?= e(TXN_TYPES[$type]) ?>">
        <?php foreach ($items as $it): ?><option value="<?= (int)$it['ID'] ?>" <?= (int)$it['ID'] === $keep['item'] ? 'selected' : '' ?>><?= e($it['Name']) ?><?= lib_all() ? ' · ' . e($it['Library']) : '' ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
    <?php if (!$free): ?><small class="muted">Nothing is free right now.<?= can_edit_records() ? ' Add items under <a href="' . e($base . '?v=items') . '">Items &amp; rates</a>.' : ' Ask a librarian to add items.' ?></small><?php endif; ?></label>
  <label><span>Number of hours</span><input type="number" name="hours" value="<?= (int)$keep['hours'] ?>" min="1" max="<?= TXN_MAX_HOURS ?>" required></label>
  <p class="muted" style="margin:0">Late penalty: <?= e(implode(' · ', array_map(fn($t) => TXN_TYPES[$t] . ' ' . money($rates[$t]['Rate_per_hour']) . '/hour', array_keys(TXN_TYPES)))) ?>. Late time is rounded up to the next hour.</p>
  <div class="actions"><button class="btn primary">Check out</button></div>
</form>

<p class="tabs no-print"><?php foreach ($tabs as $k => [$label, $n]): ?><a class="<?= $k === $f ? 'on' : '' ?>" href="<?= e($base . '?f=' . $k) ?>"><?= e($label) ?><?= $n !== null ? ' (' . $n . ')' : '' ?></a><?php endforeach; ?></p>
<form class="toolbar no-print" method="get"><input type="hidden" name="f" value="<?= e($f) ?>">
  <select name="t" aria-label="Type"><option value="">All types</option><?php foreach (TXN_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === $tf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search item, borrower ID or name…">
  <?php if (lib_all()): ?><select name="lib" aria-label="Library"><option value="">All libraries</option><?php foreach ($libs as $l): ?><option <?= $l === $lf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn">List</button> <a class="btn ghost" href="<?= e($base . '?f=' . $f) ?>">Clear</a> <button type="button" class="btn ghost" data-print>Print</button></form>
<p class="muted"><?= number_format(count($rows)) ?> record<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 300 ? ' (showing the latest 300)' : '' ?></p>

<div class="table-wrap"><table class="data" data-sortable><thead><tr>
  <th>Type</th><th>Item</th><th>Borrower</th><th>Time in</th><th>Time due</th><th>Time out</th><th>Penalty</th><th>Status</th>
  <?php if (lib_all()): ?><th>Library</th><?php endif; ?><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r):
    $inUse = $r['Status'] === 'In use'; $od = $inUse && strtotime($r['Due_at']) < strtotime($now);
    $estLate = $od ? txn_late_hours($r['Due_at'], $now) : 0; $estPen = $estLate * (float)$rates[$r['Type']]['Rate_per_hour']; ?>
<tr<?= $od ? ' class="overdue"' : '' ?>>
  <td><?= e(TXN_TYPES[$r['Type']]) ?></td><td><?= e($r['Item_name']) ?></td>
  <td><?= e($r['Borrower_name']) ?> <small class="muted">(<?= e($r['ID_Number']) ?>)</small></td>
  <td><?= e(date('M j, g:i A', strtotime($r['Time_in']))) ?></td><td><?= e(date('M j, g:i A', strtotime($r['Due_at']))) ?></td>
  <td><?= $r['Time_out'] ? e(date('M j, g:i A', strtotime($r['Time_out']))) : '' ?></td>
  <td><?php if ($inUse): ?><?= $od ? e(money($estPen)) . ' <small class="muted">so far</small>' : '' ?><?php elseif ((float)$r['Penalty'] > 0): ?><?= e(money($r['Penalty'])) ?> <small class="muted">(<?= (int)$r['Late_hours'] ?> h late, <?= $r['Paid'] ? 'paid' : 'unpaid' ?>)</small><?php endif; ?></td>
  <td><?= $od ? '<strong class="danger">Overdue</strong> <small>' . e(txn_time_note($r)) . '</small>' : e($r['Status']) . ($inUse ? ' <small class="muted">' . e(txn_time_note($r)) . '</small>' : '') ?></td>
  <?php if (lib_all()): ?><td><?= e($r['Library']) ?></td><?php endif; ?>
  <td class="no-print row-actions">
    <?php if ($inUse): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="checkin"><input type="hidden" name="f" value="<?= e($f) ?>"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>">
        <?php if ($od): ?><label style="font-weight:400"><input type="checkbox" name="paid" value="1"> penalty paid</label> <?php endif; ?><button class="link">Check in</button></form>
    <?php elseif ((float)$r['Penalty'] > 0 && !$r['Paid']): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="paid"><input type="hidden" name="f" value="<?= e($f) ?>"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link">Mark paid</button></form>
    <?php endif; if (can_edit_records()): ?>
      <form method="post" data-confirm="Delete this record?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="delete_log"><input type="hidden" name="f" value="<?= e($f) ?>"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link danger">Delete</button></form>
    <?php endif; ?></td>
</tr><?php endforeach; if (!$rows): ?><tr><td colspan="<?= lib_all() ? 10 : 9 ?>" class="muted">No records found.</td></tr><?php endif; ?>
</tbody></table></div>

<?php
// =====================================================================  STATISTICS
elseif ($v === 'stats'):
    $from = $_GET['from'] ?? date('Y-m-01'); $to = $_GET['to'] ?? date('Y-m-d');
    if (!$okDate($from)) $from = date('Y-m-01'); if (!$okDate($to)) $to = date('Y-m-d');
    if ($to < $from) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) / 86400 > 366) $from = date('Y-m-d', strtotime("$to -366 days"));
    $tf = $_GET['t'] ?? ''; $lf = $_GET['lib'] ?? '';
    $args = ['f' => "$from 00:00:00", 't2' => date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00'];
    $w = 'Time_in >= :f AND Time_in < :t2' . $libWhere('Library', $args, $lf);
    if (isset(TXN_TYPES[$tf])) { $w .= ' AND Type = :t'; $args['t'] = $tf; }

    $st = $pdo->prepare("SELECT Type, COUNT(*) uses,
        AVG(CASE WHEN Status='Returned' THEN TIMESTAMPDIFF(MINUTE, Time_in, Time_out) END) avg_min,
        SUM(Late_hours > 0 OR (Status='In use' AND Due_at < :now)) late_uses,
        SUM(Penalty) charged, SUM(CASE WHEN Paid=1 THEN Penalty ELSE 0 END) collected
        FROM txn_log WHERE $w GROUP BY Type"); $st->execute($args + ['now' => $now]);
    $byType = []; foreach ($st->fetchAll() as $r) $byType[$r['Type']] = $r;
    $tot = ['uses' => 0, 'late' => 0, 'charged' => 0.0, 'collected' => 0.0];
    foreach ($byType as $r) { $tot['uses'] += $r['uses']; $tot['late'] += $r['late_uses']; $tot['charged'] += $r['charged']; $tot['collected'] += $r['collected']; }
    $st = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE, Time_in, Time_out)) FROM txn_log WHERE $w AND Status='Returned'"); $st->execute($args); $avgMin = $st->fetchColumn();

    $st = $pdo->prepare("SELECT DATE(Time_in) d, COUNT(*) n FROM txn_log WHERE $w GROUP BY d ORDER BY d"); $st->execute($args); $perDay = $st->fetchAll();
    $maxDay = max(1, ...array_map(fn($r) => (int)$r['n'], $perDay ?: [['n' => 1]]));
    $st = $pdo->prepare("SELECT Type, Item_name, Library, COUNT(*) n FROM txn_log WHERE $w GROUP BY Type, Item_name, Library ORDER BY n DESC, Type, Item_name LIMIT 10"); $st->execute($args); $top = $st->fetchAll();
    $maxTop = max(1, ...array_map(fn($r) => (int)$r['n'], $top ?: [['n' => 1]]));
    $mins = fn($m) => $m === null ? '—' : (intdiv((int)round($m), 60) ? intdiv((int)round($m), 60) . ' h ' : '') . ((int)round($m) % 60) . ' min';
?>
<form class="toolbar no-print" method="get"><input type="hidden" name="v" value="stats">
  <input type="date" name="from" value="<?= e($from) ?>" aria-label="From"> <input type="date" name="to" value="<?= e($to) ?>" aria-label="To">
  <select name="t" aria-label="Type"><option value="">All types</option><?php foreach (TXN_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === $tf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <?php if (lib_all()): ?><select name="lib" aria-label="Library"><option value="">All libraries</option><?php foreach ($libs as $l): ?><option <?= $l === $lf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn">Show</button> <button type="button" class="btn ghost" data-print>Print</button></form>
<p class="muted"><?= e(fdate($from)) ?> to <?= e(fdate($to)) ?> · by date checked out<?= lib_all() ? ' · ' . e($lf ?: 'All libraries') : ' · ' . e(user_library()) ?></p>
<div class="cards">
  <div class="card stat"><span class="num"><?= number_format($tot['uses']) ?></span><span>Total uses</span></div>
  <div class="card stat"><span class="num"><?= e($mins($avgMin)) ?></span><span>Average time used (checked in)</span></div>
  <div class="card stat"><span class="num"><?= number_format($tot['late']) ?></span><span>Overdue uses</span></div>
  <div class="card stat"><span class="num"><?= e(money($tot['charged'])) ?></span><span>Penalties charged</span></div>
  <div class="card stat"><span class="num"><?= e(money($tot['collected'])) ?></span><span>Penalties collected</span></div>
</div>

<section class="card"><h2>By type</h2>
<div class="table-wrap"><table class="data"><thead><tr><th>Type</th><th>Uses</th><th>Average time used</th><th>Overdue uses</th><th>Penalty charged</th><th>Collected</th></tr></thead><tbody>
<?php foreach (TXN_TYPES as $k => $l): $r = $byType[$k] ?? null; if (isset(TXN_TYPES[$tf]) && $tf !== $k) continue; ?>
  <tr><td><?= e($l) ?></td><td><?= number_format((int)($r['uses'] ?? 0)) ?></td><td><?= e($mins($r['avg_min'] ?? null)) ?></td><td><?= number_format((int)($r['late_uses'] ?? 0)) ?></td><td><?= e(money($r['charged'] ?? 0)) ?></td><td><?= e(money($r['collected'] ?? 0)) ?></td></tr>
<?php endforeach; ?></tbody></table></div></section>

<?php
  $hs = $pdo->prepare("SELECT HOUR(Time_in) h, COUNT(*) n FROM txn_log WHERE $w GROUP BY h"); $hs->execute($args);
  $byHour = []; foreach ($hs->fetchAll() as $r) $byHour[(int)$r['h']] = (int)$r['n'];
  $maxHour = max(1, ...($byHour ?: [1])); $hourSum = array_sum($byHour);
?>
<section class="card"><h2>Uses by hour<?= isset(TXN_TYPES[$tf]) ? ' · ' . e(TXN_TYPES[$tf]) : '' ?></h2>
<p class="muted" style="margin-top:0">When items were checked out during the day (7:00 AM to 9:00 PM) in this period. Total <?= number_format($hourSum) ?>.</p>
<?php for ($h = 7; $h <= 21; $h++): $n = $byHour[$h] ?? 0; ?><div class="bar-row"><span class="bar-label"><?= e(date('g:00 A', strtotime(sprintf('%02d:00', $h)))) ?></span><span class="bar"><i style="width:<?= round($n / $maxHour * 100) ?>%"></i></span><span class="bar-n"><?= number_format($n) ?></span></div>
<?php endfor; ?></section>

<section class="card"><h2>Uses per day</h2>
<?php foreach ($perDay as $r): ?><div class="bar-row"><span class="bar-label"><?= e(date('M j', strtotime($r['d']))) ?></span><span class="bar"><i style="width:<?= round($r['n'] / $maxDay * 100) ?>%"></i></span><span class="bar-n"><?= number_format($r['n']) ?></span></div>
<?php endforeach; if (!$perDay): ?><p class="muted">No uses in this period.</p><?php endif; ?></section>

<section class="card"><h2>Most used items</h2>
<?php foreach ($top as $r): ?><div class="bar-row"><span class="bar-label"><?= e(txn_title($r['Type'], $r['Item_name'])) ?></span><span class="bar"><i style="width:<?= round($r['n'] / $maxTop * 100) ?>%"></i></span><span class="bar-n"><?= number_format($r['n']) ?></span></div>
<?php endforeach; if (!$top): ?><p class="muted">No uses in this period.</p><?php endif; ?></section>

<?php
// =====================================================================  ITEMS & RATES
else:
    $args = []; $lw = $libWhere('i.Library', $args);
    $st = $pdo->prepare("SELECT i.*, (SELECT COUNT(*) FROM txn_log l WHERE l.ItemID = i.ID) uses, (SELECT COUNT(*) FROM txn_log l WHERE l.ItemID = i.ID AND l.Status='In use') busy FROM txn_item i WHERE 1=1$lw ORDER BY i.Type, i.Library, LENGTH(i.Name), i.Name");
    $st->execute($args); $items = $st->fetchAll();
?>
<form method="post" class="card form" autocomplete="off">
  <h2 style="margin:0">Penalty rates</h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="save_rates">
  <?php foreach (TXN_TYPES as $k => $l): ?>
    <label><span><?= e($l) ?>: penalty per late hour · default hours</span>
      <span style="display:flex;gap:8px;font-weight:400"><input type="number" name="rate[<?= e($k) ?>]" value="<?= e($rates[$k]['Rate_per_hour']) ?>" min="0" max="9999" step="0.01" required> <input type="number" name="hours[<?= e($k) ?>]" value="<?= (int)$rates[$k]['Default_hours'] ?>" min="1" max="<?= TXN_MAX_HOURS ?>" required></span></label>
  <?php endforeach; ?>
  <p class="muted" style="margin:0">Changing a rate affects check-ins from now on, not records already checked in.</p>
  <div class="actions"><button class="btn primary">Save rates</button></div>
</form>

<form method="post" class="card form" autocomplete="off">
  <h2 style="margin:0">Add items</h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="add_item">
  <label><span>Type</span><select name="type" required><option value="">— choose —</option><?php foreach (TXN_TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
  <?php if (lib_all()): ?><label><span>Library</span><select name="library" required><option value="">— choose —</option><?php foreach ($libs as $l): ?><option><?= e($l) ?></option><?php endforeach; ?></select></label>
  <?php else: ?><p class="muted" style="margin:0">Library: <?= e(user_library()) ?></p><?php endif; ?>
  <label><span>One name (e.g. Room A)</span><input type="text" name="name" maxlength="40"></label>
  <label><span>…or a number range: prefix, from, to (e.g. Locker, 1, 50)</span>
    <span style="display:flex;gap:8px;font-weight:400"><input type="text" name="prefix" maxlength="30" placeholder="Prefix"> <input type="number" name="from" min="1" placeholder="From"> <input type="number" name="to" min="1" placeholder="To"></span></label>
  <div class="actions"><button class="btn primary">Add</button></div>
</form>

<p class="muted"><?= number_format(count($items)) ?> item<?= count($items) === 1 ? '' : 's' ?></p>
<div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Type</th><th>Name</th><?php if (lib_all()): ?><th>Library</th><?php endif; ?><th>Uses</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($items as $it): ?><tr>
  <td><?= e(TXN_TYPES[$it['Type']]) ?></td><td><?= e($it['Name']) ?></td><?php if (lib_all()): ?><td><?= e($it['Library']) ?></td><?php endif; ?>
  <td><?= number_format((int)$it['uses']) ?></td><td><?= $it['busy'] ? 'In use' : ($it['Active'] ? 'Free' : 'Turned off') ?></td>
  <td class="row-actions">
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="toggle_item"><input type="hidden" name="id" value="<?= (int)$it['ID'] ?>"><button class="link"><?= $it['Active'] ? 'Turn off' : 'Turn on' ?></button></form>
    <?php if (!(int)$it['uses']): ?><form method="post" data-confirm="Delete this item?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="delete_item"><input type="hidden" name="id" value="<?= (int)$it['ID'] ?>"><button class="link danger">Delete</button></form><?php endif; ?></td>
</tr><?php endforeach; if (!$items): ?><tr><td colspan="<?= lib_all() ? 6 : 5 ?>" class="muted">No items yet. Add some above.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
