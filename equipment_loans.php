<?php
// Equipment circulation: check out equipment, check it in, and see the loan status.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
require __DIR__ . '/includes/equipment.php';
$pdo = db();
$pageTitle = 'Equipment loans';
$base = 'equipment_loans.php';

try { $pdo->query('SELECT 1 FROM equipment_loan LIMIT 1'); }
catch (PDOException $ex) {
    include __DIR__ . '/includes/header.php';
    echo '<h1>Equipment loans</h1><div class="card"><p>The loan table is missing. Import <code>02_equipment_circulation.sql</code> in phpMyAdmin, then reload this page.</p></div>';
    include __DIR__ . '/includes/footer.php'; exit;
}

$libs = libraries();
$formError = '';
$keep = ['idnum' => '', 'sn' => '', 'item' => '', 'hours' => EQ_DEFAULT_HOURS, 'library' => lib_all() ? '' : user_library()];

// ---------- actions (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'checkout') {
        $keep = ['idnum' => trim($_POST['idnum'] ?? ''), 'sn' => trim($_POST['sn'] ?? ''), 'item' => (string)(int)($_POST['item'] ?? 0),
                 'hours' => (int)($_POST['hours'] ?? EQ_DEFAULT_HOURS), 'library' => lib_all() ? trim($_POST['library'] ?? '') : user_library()];
        if ($keep['idnum'] === '') $formError = 'Enter or scan the borrower ID.';
        elseif (lib_all() && !in_array($keep['library'], $libs, true)) $formError = 'Pick the library this loan belongs to.';
        else {
            // A scanned serial number wins over the dropdown.
            $item = $keep['sn'] !== '' ? eq_by_sn($keep['sn']) : ((int)$keep['item'] ? eq_by_id((int)$keep['item']) : null);
            if ($keep['sn'] !== '' && !$item) $formError = "No equipment has the serial number \"{$keep['sn']}\".";
            else {
                [$ok, $msg] = eq_checkout($keep['idnum'], $item, $keep['hours'], $keep['library']);
                if ($ok) { flash($msg); redirect($base); }
                $formError = $msg;
            }
        }
    } elseif (in_array($do, ['checkin', 'lost'], true)) {
        $loan = eq_loan_row((int)($_POST['id'] ?? 0));
        if (!$loan) flash('Not done: loan not found.');
        else { [$ok, $msg] = $do === 'checkin' ? eq_checkin($loan) : eq_mark_lost($loan); flash($msg); }
        redirect($base . '?' . http_build_query(['f' => $_POST['f'] ?? 'current']));
    }
}

// ---------- list ----------
$f = $_GET['f'] ?? 'current';
if (!in_array($f, ['current', 'overdue', 'lost', 'returned', 'all'], true)) $f = 'current';
$q = trim($_GET['q'] ?? ''); $lf = $_GET['lib'] ?? '';
$now = date('Y-m-d H:i:s');
$where = ['1=1']; $args = [];
switch ($f) {
    case 'current':  $where[] = "Status = 'Out'"; break;
    case 'overdue':  $where[] = "Status = 'Out' AND Due_at < :now"; $args['now'] = $now; break;
    case 'lost':     $where[] = "Status = 'Lost'"; break;
    case 'returned': $where[] = "Status = 'Returned'"; break;
}
if ($q !== '') {
    $like = [];
    foreach (['Equipment', 'SN', 'ID_Number', 'Borrower_name'] as $i => $col) { $like[] = "$col LIKE :q$i"; $args["q$i"] = "%$q%"; }
    $where[] = '(' . implode(' OR ', $like) . ')';
}
if (!lib_all()) { $where[] = 'Library = :lib'; $args['lib'] = user_library(); }
elseif (in_array($lf, $libs, true)) { $where[] = 'Library = :lib'; $args['lib'] = $lf; }
$order = in_array($f, ['returned', 'all'], true) ? 'Date_out DESC' : 'Due_at ASC';
$st = $pdo->prepare('SELECT * FROM equipment_loan WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT 300");
$st->execute($args); $rows = $st->fetchAll();

// Counts for the tabs (respecting the library restriction).
$libSql = !lib_all() ? ' AND Library = ' . $pdo->quote(user_library()) : '';
$cnt = $pdo->prepare("SELECT COALESCE(SUM(Status='Out'),0) AS cur, COALESCE(SUM(Status='Out' AND Due_at < ?),0) AS od, COALESCE(SUM(Status='Lost'),0) AS lost FROM equipment_loan WHERE 1=1$libSql");
$cnt->execute([$now]); $c = $cnt->fetch();
$tabs = ['current' => ['On loan', (int)$c['cur']], 'overdue' => ['Overdue', (int)$c['od']], 'lost' => ['Lost', (int)$c['lost']], 'returned' => ['Returned', null], 'all' => ['All', null]];
$avail = eq_available_items();

include __DIR__ . '/includes/header.php'; ?>
<h1>Equipment loans</h1>
<?php if ($formError): ?><div class="err" role="alert"><?= e($formError) ?></div><?php endif; ?>

<form method="post" class="card form no-print" autocomplete="off">
  <h2 style="margin:0">Check out</h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="checkout">
  <label><span>Borrower ID (scan or type)</span><input type="text" name="idnum" value="<?= e($keep['idnum']) ?>" maxlength="30" required autofocus></label>
  <label><span>Scan serial number (SN)</span><input type="text" name="sn" value="<?= e($keep['sn']) ?>" maxlength="15" placeholder="Scan the equipment's SN"></label>
  <label><span>…or pick equipment from the list (for items without an SN)</span>
    <select name="item"><option value="">— choose —</option>
      <?php foreach ($avail as $it): ?><option value="<?= (int)$it['eqID'] ?>" <?= (string)$it['eqID'] === $keep['item'] ? 'selected' : '' ?>><?= e(eq_label($it)) ?></option><?php endforeach; ?></select>
    <?php if (!$avail): ?><small class="muted">Nothing is available right now.</small><?php endif; ?></label>
  <label><span>Number of hours</span><input type="number" name="hours" value="<?= (int)$keep['hours'] ?>" min="1" max="<?= EQ_MAX_HOURS ?>" required></label>
  <?php if (lib_all()): ?>
    <label><span>Library</span><select name="library" required><option value="">— choose —</option>
      <?php foreach ($libs as $l): ?><option <?= $l === $keep['library'] ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <?php else: ?><p class="muted" style="margin:0">Library: <?= e(user_library()) ?></p><?php endif; ?>
  <div class="actions"><button class="btn primary">Check out</button></div>
</form>

<p class="tabs no-print"><?php foreach ($tabs as $k => [$label, $n]): ?><a class="<?= $k === $f ? 'on' : '' ?>" href="<?= e($base . '?f=' . $k) ?>"><?= e($label) ?><?= $n !== null ? ' (' . $n . ')' : '' ?></a><?php endforeach; ?></p>
<form class="toolbar no-print" method="get"><input type="hidden" name="f" value="<?= e($f) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search equipment, SN, borrower ID or name…">
  <?php if (lib_all()): ?><select name="lib" aria-label="Library"><option value="">All libraries</option>
    <?php foreach ($libs as $l): ?><option <?= $l === $lf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn">List</button> <a class="btn ghost" href="<?= e($base . '?f=' . $f) ?>">Clear</a>
  <button type="button" class="btn ghost" data-print>Print</button></form>
<p class="muted"><?= number_format(count($rows)) ?> loan<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 300 ? ' (showing the latest 300)' : '' ?></p>

<div class="table-wrap"><table class="data" data-sortable><thead><tr>
  <th>Equipment</th><th>SN</th><th>Borrower</th><th>Out</th><th>Due</th><th>Returned</th><th>Status</th>
  <?php if (lib_all()): ?><th>Library</th><?php endif; ?><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r): $od = eq_is_overdue($r, $now); ?><tr<?= $od ? ' class="overdue"' : '' ?>>
  <td><?= e($r['Equipment']) ?></td><td><?= e($r['SN']) ?></td>
  <td><?= e($r['Borrower_name']) ?> <small class="muted">(<?= e($r['ID_Number']) ?>)</small></td>
  <td><?= e(date('M j, g:i A', strtotime($r['Date_out']))) ?></td><td><?= e(date('M j, g:i A', strtotime($r['Due_at']))) ?></td>
  <td><?= $r['Returned_at'] ? e(date('M j, g:i A', strtotime($r['Returned_at']))) : '' ?></td>
  <td><?= $od ? '<strong class="danger">Overdue</strong> <small>' . e(eq_time_note($r)) . '</small>' : e($r['Status']) . ($r['Status'] === 'Out' ? ' <small class="muted">' . e(eq_time_note($r)) . '</small>' : '') ?></td>
  <?php if (lib_all()): ?><td><?= e($r['Library']) ?></td><?php endif; ?>
  <td class="no-print row-actions">
    <?php if ($r['Status'] !== 'Returned'): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="checkin"><input type="hidden" name="f" value="<?= e($f) ?>"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link">Check in</button></form>
    <?php endif; if ($r['Status'] === 'Out'): ?>
      <form method="post" data-confirm="Mark this equipment as lost?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="lost"><input type="hidden" name="f" value="<?= e($f) ?>"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link danger">Lost</button></form>
    <?php endif; ?>
      <a href="balance.php?idnum=<?= e(urlencode($r['ID_Number'])) ?>">Balance</a> <a href="circulation.php?idnum=<?= e(urlencode($r['ID_Number'])) ?>">Patron</a></td>
</tr><?php endforeach; if (!$rows): ?><tr><td colspan="<?= lib_all() ? 9 : 8 ?>" class="muted">No loans found.</td></tr><?php endif; ?>
</tbody></table></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
