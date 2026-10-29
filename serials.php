<?php
// Serials: titles (with subscription expiry), issues monitoring, check-out / check-in of issues, and the serial inventory.
//   ?v=titles (default)   ?v=issues&t=<title id>   ?v=circ   ?v=inventory
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
require __DIR__ . '/includes/serials.php';
$pdo = db();
$pageTitle = 'Serials';
$base = 'serials.php';

// The Library column comes from 05_serials.sql. Say so clearly if it has not been imported yet.
try { $pdo->query('SELECT Library FROM Serial_TitleTemp LIMIT 1'); $pdo->query('SELECT IDD FROM TempSerialTransaction LIMIT 1'); }
catch (PDOException $ex) {
    include __DIR__ . '/includes/header.php';
    echo '<h1>Serials</h1><div class="card"><p>The serial tables need updating. Import <code>05_serials.sql</code> in phpMyAdmin, then reload this page.</p></div>';
    include __DIR__ . '/includes/footer.php'; exit;
}

$libs = libraries();
$v = $_GET['v'] ?? 'titles';
if (!in_array($v, ['titles', 'issues', 'circ', 'inventory'], true)) $v = 'titles';
$okDate = fn($d) => ($x = DateTime::createFromFormat('Y-m-d', (string)$d)) && $x->format('Y-m-d') === $d;
$tid = (int)($_GET['t'] ?? 0);
$formError = '';
$freq = ['Daily', 'Weekly', 'Biweekly', 'Semi-monthly', 'Monthly', 'Bimonthly', 'Quarterly', 'Semi-annual', 'Annual', 'Irregular'];
$blankTitle = ['Serial_Title' => '', 'ISSN' => '', 'Frequency' => '', 'Agent' => '', 'Price' => '', 'Code' => '', 'Focus' => '', 'Subject' => '', 'Expiration' => '', 'Date_renewed' => '', 'Remarks' => '', 'Library' => lib_all() ? '' : user_library()];
$tRow = $blankTitle; $editT = 0;
$blankIssue = ['Date_Year' => '', 'Volume' => '', 'Number' => '', 'Date_received' => date('Y-m-d')];
$iRow = $blankIssue; $editI = 0;

// ---------- actions (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $ed = can_edit_records();

    if ($do === 'save_title' && $ed) {
        $editT = (int)($_POST['id'] ?? 0); $old = $editT ? ser_title($editT) : null;
        if ($editT && !$old) { flash('Not done: title not found.'); redirect($base); }
        $tRow = [];
        foreach (['Serial_Title', 'ISSN', 'Frequency', 'Agent', 'Code', 'Focus', 'Subject', 'Remarks'] as $k) $tRow[$k] = trim($_POST[$k] ?? '');
        $tRow['Price'] = trim($_POST['Price'] ?? ''); $tRow['Expiration'] = trim($_POST['Expiration'] ?? ''); $tRow['Date_renewed'] = trim($_POST['Date_renewed'] ?? '');
        $tRow['Library'] = lib_all() ? trim($_POST['Library'] ?? '') : user_library();
        $dup = $pdo->prepare('SELECT ID FROM Serial_TitleTemp WHERE Code = ? AND ID <> ? LIMIT 1'); $dup->execute([$tRow['Code'], $editT]);
        if ($tRow['Serial_Title'] === '') $formError = 'Enter the serial title.';
        elseif ($tRow['Code'] === '') $formError = 'Enter a short code for the title (it links the issues to it).';
        elseif ($dup->fetch()) $formError = "The code \"{$tRow['Code']}\" is already used by another title.";
        elseif ($tRow['Price'] !== '' && (!is_numeric($tRow['Price']) || $tRow['Price'] < 0)) $formError = 'Price must be a number.';
        elseif (($tRow['Expiration'] !== '' && !$okDate($tRow['Expiration'])) || ($tRow['Date_renewed'] !== '' && !$okDate($tRow['Date_renewed']))) $formError = 'Use valid dates for expiration and renewal.';
        elseif (!in_array($tRow['Library'], $libs, true)) $formError = 'Pick the library this title belongs to.';
        elseif (strlen($tRow['Serial_Title']) > 200 || strlen($tRow['Code']) > 50 || strlen($tRow['ISSN']) > 15 || strlen($tRow['Frequency']) > 40 || strlen($tRow['Agent']) > 100 || strlen($tRow['Focus']) > 20 || strlen($tRow['Subject']) > 60 || strlen($tRow['Remarks']) > 250) $formError = 'One of the fields is too long.';
        else {
            $vals = [$tRow['Serial_Title'], $tRow['ISSN'] ?: null, $tRow['Frequency'] ?: null, $tRow['Agent'] ?: null, $tRow['Price'] === '' ? 0 : $tRow['Price'], $tRow['Code'], $tRow['Focus'] ?: null,
                     $tRow['Subject'] ?: null, $tRow['Expiration'] ? $tRow['Expiration'] . ' 00:00:00' : null, $tRow['Date_renewed'] ? $tRow['Date_renewed'] . ' 00:00:00' : null, $tRow['Remarks'] ?: null, $tRow['Library']];
            if ($old) {
                $pdo->prepare('UPDATE Serial_TitleTemp SET Serial_Title=?, ISSN=?, Frequency=?, Agent=?, Price=?, Code=?, Focus=?, Subject=?, Expiration=?, Date_renewed=?, Remarks=?, Library=? WHERE ID=?')->execute(array_merge($vals, [$old['ID']]));
                if (strcasecmp($old['Code'], $tRow['Code']) !== 0 || $old['Code'] !== $tRow['Code']) $pdo->prepare('UPDATE SerialsTemp SET Code = ? WHERE Code = ?')->execute([$tRow['Code'], $old['Code']]);   // keep the issues attached
                flash('Title saved.');
            } else {
                $pdo->prepare('INSERT INTO Serial_TitleTemp (Serial_Title, ISSN, Frequency, Agent, Price, Code, Focus, Subject, Expiration, Date_renewed, Remarks, Library, TimesOut) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0)')->execute($vals);
                flash('Title added. Now add its issues.');
            }
            redirect($base);
        }
        $v = 'titles';
    } elseif ($do === 'delete_title' && $ed) {
        $t = ser_title((int)($_POST['id'] ?? 0));
        if (!$t) flash('Not done: title not found.');
        else {
            $n = $pdo->prepare('SELECT COUNT(*) FROM SerialsTemp WHERE Code = ?'); $n->execute([$t['Code']]);
            if ((int)$n->fetchColumn() > 0) flash('Not deleted: this title still has issues. Delete the issues first.');
            else { $pdo->prepare('DELETE FROM Serial_TitleTemp WHERE ID = ?')->execute([$t['ID']]); flash('Title deleted.'); }
        }
        redirect($base);
    } elseif (in_array($do, ['save_issue', 'receive_issue', 'delete_issue'], true) && $ed) {
        $t = ser_title((int)($_POST['t'] ?? 0));
        if (!$t) { flash('Not done: title not found.'); redirect($base); }
        $back = $base . '?v=issues&t=' . $t['ID'];
        if ($do === 'save_issue') {
            $editI = (int)($_POST['id'] ?? 0); $old = $editI ? ser_issue($editI) : null;
            $iRow = ['Date_Year' => trim($_POST['Date_Year'] ?? ''), 'Volume' => trim($_POST['Volume'] ?? ''), 'Number' => trim($_POST['Number'] ?? ''), 'Date_received' => trim($_POST['Date_received'] ?? '')];
            if ($editI && (!$old || strcasecmp((string)$old['Code'], $t['Code']) !== 0)) { flash('Not done: issue not found.'); redirect($back); }
            $dup = $pdo->prepare('SELECT IDD FROM SerialsTemp WHERE Code = ? AND Date_Year = ? AND Volume = ? AND Number = ? AND IDD <> ? LIMIT 1'); $dup->execute([$t['Code'], $iRow['Date_Year'], $iRow['Volume'], $iRow['Number'], $editI]);
            if ($iRow['Date_Year'] === '' && $iRow['Volume'] === '' && $iRow['Number'] === '') $formError = 'Enter the date/year, volume or number of the issue.';
            elseif (strlen($iRow['Date_Year']) > 50 || strlen($iRow['Volume']) > 15 || strlen($iRow['Number']) > 15) $formError = 'One of the fields is too long.';
            elseif ($iRow['Date_received'] !== '' && !$okDate($iRow['Date_received'])) $formError = 'Use a valid received date, or leave it empty if the issue has not arrived.';
            elseif ($dup->fetch()) $formError = 'This issue is already listed for this title.';
            else {
                $rec = $iRow['Date_received'] !== '' ? $iRow['Date_received'] . ' 00:00:00' : null;
                if ($old) { $pdo->prepare('UPDATE SerialsTemp SET Date_Year=?, Volume=?, Number=?, Date_received=? WHERE IDD=?')->execute([$iRow['Date_Year'], $iRow['Volume'], $iRow['Number'], $rec, $old['IDD']]); flash('Issue saved.'); }
                else { $pdo->prepare('INSERT INTO SerialsTemp (Date_Year, Volume, Number, Code, Date_received, Status) VALUES (?,?,?,?,?,0)')->execute([$iRow['Date_Year'], $iRow['Volume'], $iRow['Number'], $t['Code'], $rec]); flash($rec ? 'Issue added as received.' : 'Issue added (not received yet).'); }
                redirect($back);
            }
            $v = 'issues'; $tid = $t['ID'];
        } else {
            $iss = ser_issue((int)($_POST['id'] ?? 0));
            if (!$iss || strcasecmp((string)$iss['Code'], $t['Code']) !== 0) flash('Not done: issue not found.');
            elseif ($do === 'receive_issue') { $pdo->prepare('UPDATE SerialsTemp SET Date_received = ? WHERE IDD = ? AND Date_received IS NULL')->execute([date('Y-m-d 00:00:00'), $iss['IDD']]); flash('Issue marked as received.'); }
            elseif ((int)$iss['Status'] !== 0) flash('Not deleted: this issue is out or lost.');
            else { $pdo->prepare('DELETE FROM SerialsTemp WHERE IDD = ?')->execute([$iss['IDD']]); flash('Issue deleted.'); }
            redirect($back);
        }
    } elseif ($do === 'checkout') {
        $idn = trim($_POST['idnum'] ?? ''); $idd = (int)($_POST['issue'] ?? 0);
        $scan = trim($_POST['code'] ?? '');
        if ($scan !== '') $idd = preg_match('/^SER-(\d+)$/i', $scan, $m) ? (int)$m[1] : 0;
        if ($idn === '') $formError = 'Enter or scan the borrower ID.';
        elseif ($scan !== '' && !$idd) $formError = "\"$scan\" is not an issue code (it looks like SER-12).";
        else { [$ok, $msg] = ser_checkout($idn, $idd); if ($ok) { flash($msg); redirect($base . '?v=circ'); } $formError = $msg; }
        $v = 'circ';
    } elseif (in_array($do, ['return', 'renew', 'lost'], true)) {
        $st = $pdo->prepare("SELECT * FROM Borrower WHERE ID = ? AND Type = 'Serial' AND Date_returned IS NULL"); $st->execute([(int)($_POST['id'] ?? 0)]); $loan = $st->fetch();
        if (!$loan || (!lib_all() && $loan['Library'] !== user_library())) flash('Not done: loan not found.');
        else {
            $idn = (string)$loan['ID_Number'];
            [, $msg] = $do === 'return' ? circ_return((int)$loan['ID'], $idn, !empty($_POST['paid'])) : ($do === 'renew' ? circ_renew((int)$loan['ID'], $idn) : circ_lost((int)$loan['ID'], $idn));
            flash($msg);
        }
        redirect($base . '?v=circ');
    }
}
if ($v === 'titles' && !$editT && isset($_GET['edit']) && can_edit_records() && ($t = ser_title((int)$_GET['edit']))) {
    $editT = (int)$t['ID']; $tRow = $t;
    foreach (['Expiration', 'Date_renewed'] as $k) $tRow[$k] = $t[$k] ? substr($t[$k], 0, 10) : '';
}
if ($v === 'issues' && !$editI && isset($_GET['edit']) && can_edit_records() && ($i = ser_issue((int)$_GET['edit']))) {
    $editI = (int)$i['IDD']; $iRow = ['Date_Year' => $i['Date_Year'], 'Volume' => $i['Volume'], 'Number' => $i['Number'], 'Date_received' => $i['Date_received'] ? substr($i['Date_received'], 0, 10) : ''];
}

$title = $tid ? ser_title($tid) : null;
if ($v === 'issues' && !$title) { flash('Pick a title to see its issues.'); redirect($base); }
include __DIR__ . '/includes/header.php';
echo '<h1>Serials</h1><p class="tabs no-print">';
foreach (['titles' => 'Titles', 'circ' => 'Check out / in', 'inventory' => 'Inventory'] as $k => $l) echo '<a class="' . ($k === $v || ($v === 'issues' && $k === 'titles') ? 'on' : '') . '" href="' . e($base . '?v=' . $k) . '">' . e($l) . '</a>';
echo '</p>';
if ($formError) echo '<div class="err" role="alert">' . e($formError) . '</div>';
$now = date('Y-m-d H:i:s');

// =====================================================================  TITLES
if ($v === 'titles'):
    $q = trim($_GET['q'] ?? ''); $lf = $_GET['lib'] ?? ''; $xf = $_GET['x'] ?? '';
    $ltr = strtoupper(trim($_GET['l'] ?? '')); if (!preg_match('/^[A-Z]$|^#$/', $ltr)) $ltr = '';   // A-Z index, like the old screen
    $where = ['1=1']; $args = [];
    if ($ltr === '#') $where[] = "t.Serial_Title NOT REGEXP '^[A-Za-z]'";
    elseif ($ltr !== '') { $where[] = 't.Serial_Title LIKE :ltr'; $args['ltr'] = $ltr . '%'; }
    if ($q !== '') { $like = []; foreach (['Serial_Title', 'Code', 'ISSN', 'Agent', 'Subject'] as $i => $c) { $like[] = "t.$c LIKE :q$i"; $args["q$i"] = "%$q%"; } $where[] = '(' . implode(' OR ', $like) . ')'; }
    if ($xf === 'expired') $where[] = 't.Expiration IS NOT NULL AND t.Expiration < CURDATE()';
    elseif ($xf === 'soon') $where[] = 't.Expiration IS NOT NULL AND t.Expiration >= CURDATE() AND t.Expiration <= DATE_ADD(CURDATE(), INTERVAL ' . SER_SOON_DAYS . ' DAY)';
    $w = implode(' AND ', $where) . ser_lib_sql('t.Library', $args, $lf);
    $st = $pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM SerialsTemp s WHERE s.Code = t.Code) AS issues, (SELECT COUNT(*) FROM SerialsTemp s WHERE s.Code = t.Code AND s.Date_received IS NOT NULL) AS got
        FROM Serial_TitleTemp t WHERE $w ORDER BY t.Serial_Title LIMIT 500");
    $st->execute($args); $rows = $st->fetchAll();
    $nExp = 0; $nSoon = 0; foreach ($rows as $r) { $s = ser_expiry($r['Expiration']); $nExp += $s === 'expired'; $nSoon += $s === 'soon'; }
?>
<?php if ($nExp || $nSoon): ?><div class="flash" style="position:static"><?= $nExp ? "$nExp subscription" . ($nExp === 1 ? ' has' : 's have') . ' expired. ' : '' ?><?= $nSoon ? "$nSoon expire" . ($nSoon === 1 ? 's' : '') . ' within ' . SER_SOON_DAYS . ' days.' : '' ?></div><?php endif; ?>
<?php if (can_edit_records()): ?>
<form method="post" class="card form no-print" autocomplete="off">
  <h2 style="margin:0"><?= $editT ? 'Edit title' : 'New title' ?></h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="save_title"><input type="hidden" name="id" value="<?= (int)$editT ?>">
  <label><span>Serial title</span><input type="text" name="Serial_Title" value="<?= e($tRow['Serial_Title']) ?>" maxlength="200" required></label>
  <label><span>Code (short and unique, links the issues)</span><input type="text" name="Code" value="<?= e($tRow['Code']) ?>" maxlength="50" required></label>
  <label><span>ISSN</span><input type="text" name="ISSN" value="<?= e($tRow['ISSN']) ?>" maxlength="15"></label>
  <label><span>Frequency</span><input type="text" name="Frequency" list="dl-freq" value="<?= e($tRow['Frequency']) ?>" maxlength="40"></label>
  <label><span>Agent / supplier</span><input type="text" name="Agent" value="<?= e($tRow['Agent']) ?>" maxlength="100"></label>
  <label><span>Price</span><input type="number" name="Price" value="<?= e($tRow['Price']) ?>" min="0" step="0.01"></label>
  <label><span>Focus</span><input type="text" name="Focus" value="<?= e($tRow['Focus']) ?>" maxlength="20"></label>
  <label><span>Subject</span><input type="text" name="Subject" value="<?= e($tRow['Subject']) ?>" maxlength="60"></label>
  <label><span>Subscription expires</span><input type="date" name="Expiration" value="<?= e($tRow['Expiration']) ?>"></label>
  <label><span>Date renewed</span><input type="date" name="Date_renewed" value="<?= e($tRow['Date_renewed']) ?>"></label>
  <label><span>Remarks</span><input type="text" name="Remarks" value="<?= e($tRow['Remarks']) ?>" maxlength="250"></label>
  <?php if (lib_all()): ?><label><span>Library</span><select name="Library" required><option value="">— choose —</option><?php foreach ($libs as $l): ?><option <?= $l === $tRow['Library'] ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <?php else: ?><p class="muted" style="margin:0">Library: <?= e(user_library()) ?></p><?php endif; ?>
  <div class="actions"><button class="btn primary"><?= $editT ? 'Save changes' : 'Add title' ?></button><?php if ($editT): ?><a class="btn" href="<?= e($base) ?>">Cancel</a><?php endif; ?></div>
  <datalist id="dl-freq"><?php foreach ($freq as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist>
</form>
<?php endif; ?>
<p class="tabs no-print" aria-label="Titles A to Z"><?php foreach (array_merge(['' => 'All'], array_combine(range('A', 'Z'), range('A', 'Z')), ['#' => '#']) as $k => $l): ?><a class="<?= (string)$k === $ltr ? 'on' : '' ?>" href="<?= e($base . '?' . http_build_query(['v' => 'titles', 'l' => $k, 'q' => $q, 'x' => $xf, 'lib' => $lf])) ?>"><?= e($l) ?></a><?php endforeach; ?></p>
<form class="toolbar no-print" method="get"><input type="hidden" name="v" value="titles"><input type="hidden" name="l" value="<?= e($ltr) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search title, code, ISSN, agent, subject…">
  <select name="x" aria-label="Subscription"><option value="">All subscriptions</option><option value="soon" <?= $xf === 'soon' ? 'selected' : '' ?>>Expiring within <?= SER_SOON_DAYS ?> days</option><option value="expired" <?= $xf === 'expired' ? 'selected' : '' ?>>Expired</option></select>
  <?php if (lib_all()): ?><select name="lib" aria-label="Library"><option value="">All libraries</option><?php foreach ($libs as $l): ?><option <?= $l === $lf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn">List</button> <a class="btn ghost" href="<?= e($base) ?>">Clear</a> <button type="button" class="btn ghost" data-print>Print</button></form>
<p class="muted"><?= number_format(count($rows)) ?> title<?= count($rows) === 1 ? '' : 's' ?></p>
<div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Title</th><th>Code</th><th>ISSN</th><th>Frequency</th><th>Agent</th><th>Price</th><th>Expires</th><th>Issues (received)</th><th>Times out</th><?php if (lib_all()): ?><th>Library</th><?php endif; ?><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r): $x = ser_expiry($r['Expiration']); ?><tr>
  <td><?= e($r['Serial_Title']) ?></td><td><?= e($r['Code']) ?></td><td><?= e($r['ISSN']) ?></td><td><?= e($r['Frequency']) ?></td><td><?= e($r['Agent']) ?></td>
  <td><?= $r['Price'] ? e(money($r['Price'])) : '' ?></td>
  <td><?= $r['Expiration'] ? e(fdate($r['Expiration'])) : '' ?><?= $x === 'expired' ? ' <strong class="danger">Expired</strong>' : ($x === 'soon' ? ' <strong>Expiring soon</strong>' : '') ?></td>
  <td><?= (int)$r['issues'] ?> (<?= (int)$r['got'] ?>)</td><td><?= (int)$r['TimesOut'] ?></td><?php if (lib_all()): ?><td><?= e($r['Library']) ?></td><?php endif; ?>
  <td class="no-print row-actions"><a href="<?= e($base . '?v=issues&t=' . (int)$r['ID']) ?>">Issues</a>
    <?php if (can_edit_records()): ?><a href="<?= e($base . '?edit=' . (int)$r['ID']) ?>">Edit</a>
    <?php if (!(int)$r['issues']): ?><form method="post" data-confirm="Delete this title?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="delete_title"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link danger">Delete</button></form><?php endif; endif; ?></td>
</tr><?php endforeach; if (!$rows): ?><tr><td colspan="<?= lib_all() ? 11 : 10 ?>" class="muted">No titles found.</td></tr><?php endif; ?></tbody></table></div>

<?php
// =====================================================================  ISSUES MONITORING
elseif ($v === 'issues'):
    $sf = $_GET['s'] ?? '';
    $st = $pdo->prepare('SELECT * FROM SerialsTemp WHERE Code = ? ORDER BY IDD DESC'); $st->execute([$title['Code']]); $all = $st->fetchAll();
    $cnt = ['all' => count($all), 'pending' => 0, 'out' => 0];
    foreach ($all as $i) { $s = ser_status($i); $cnt['pending'] += $s === 'Not received'; $cnt['out'] += $s === 'Out'; }
    $rows = array_filter($all, fn($i) => $sf === '' || ($sf === 'pending' && ser_status($i) === 'Not received') || ($sf === 'out' && ser_status($i) === 'Out'));
    $bn = [];   // borrower names for issues that are out
    foreach ($all as $i) if ((int)$i['Status'] === 1 && $i['ID']) { $c = client_by_id((string)$i['ID']); $bn[$i['IDD']] = $c['Name'] ?? $i['ID']; }
    $ib = $base . '?v=issues&t=' . (int)$title['ID'];
?>
<p class="no-print"><a href="<?= e($base) ?>">‹ All titles</a></p>
<section class="card"><h2 style="margin:0 0 6px"><?= e($title['Serial_Title']) ?></h2>
  <p class="muted" style="margin:0"><?= e(implode(' · ', array_filter([$title['Code'], $title['ISSN'] ? 'ISSN ' . $title['ISSN'] : '', $title['Frequency'], $title['Agent'], $title['Library']]))) ?></p>
  <?php $x = ser_expiry($title['Expiration']); if ($title['Expiration']): ?><p style="margin:6px 0 0">Subscription expires <?= e(fdate($title['Expiration'])) ?><?= $x === 'expired' ? ' <strong class="danger">(expired)</strong>' : ($x === 'soon' ? ' <strong>(expiring soon)</strong>' : '') ?></p><?php endif; ?></section>
<?php if (can_edit_records()): ?>
<form method="post" class="card form no-print" autocomplete="off">
  <h2 style="margin:0"><?= $editI ? 'Edit issue' : 'Add issue' ?></h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="save_issue"><input type="hidden" name="t" value="<?= (int)$title['ID'] ?>"><input type="hidden" name="id" value="<?= (int)$editI ?>">
  <label><span>Date / year (e.g. 10/2026)</span><input type="text" name="Date_Year" value="<?= e($iRow['Date_Year']) ?>" maxlength="50"></label>
  <label><span>Volume</span><input type="text" name="Volume" value="<?= e($iRow['Volume']) ?>" maxlength="15"></label>
  <label><span>Number</span><input type="text" name="Number" value="<?= e($iRow['Number']) ?>" maxlength="15"></label>
  <label><span>Date received (leave empty if it has not arrived)</span><input type="date" name="Date_received" value="<?= e($iRow['Date_received']) ?>"></label>
  <div class="actions"><button class="btn primary"><?= $editI ? 'Save changes' : 'Add issue' ?></button><?php if ($editI): ?><a class="btn" href="<?= e($ib) ?>">Cancel</a><?php endif; ?></div>
</form>
<?php endif; ?>
<p class="tabs no-print"><a class="<?= $sf === '' ? 'on' : '' ?>" href="<?= e($ib) ?>">All (<?= $cnt['all'] ?>)</a><a class="<?= $sf === 'pending' ? 'on' : '' ?>" href="<?= e($ib . '&s=pending') ?>">Not received (<?= $cnt['pending'] ?>)</a><a class="<?= $sf === 'out' ? 'on' : '' ?>" href="<?= e($ib . '&s=out') ?>">Out (<?= $cnt['out'] ?>)</a>
  <button type="button" class="btn ghost" data-print style="margin-left:auto">Print</button></p>
<div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Issue code</th><th>Date / year</th><th>Volume</th><th>Number</th><th>Received</th><th>Status</th><th>Borrower / due</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $i): $s = ser_status($i); ?><tr>
  <td>SER-<?= (int)$i['IDD'] ?></td><td><?= e($i['Date_Year']) ?></td><td><?= e($i['Volume']) ?></td><td><?= e($i['Number']) ?></td>
  <td><?= $i['Date_received'] ? e(fdate($i['Date_received'])) : '' ?></td><td><?= e($s) ?></td>
  <td><?= $s === 'Out' ? e(($bn[$i['IDD']] ?? '') . ' · due ' . fdate($i['DateDue'])) : '' ?></td>
  <td class="no-print row-actions"><?php if (can_edit_records()): ?>
    <?php if ($s === 'Not received'): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="receive_issue"><input type="hidden" name="t" value="<?= (int)$title['ID'] ?>"><input type="hidden" name="id" value="<?= (int)$i['IDD'] ?>"><button class="link">Receive</button></form><?php endif; ?>
    <a href="<?= e($ib . '&edit=' . (int)$i['IDD']) ?>">Edit</a>
    <?php if ((int)$i['Status'] === 0): ?><form method="post" data-confirm="Delete this issue?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="delete_issue"><input type="hidden" name="t" value="<?= (int)$title['ID'] ?>"><input type="hidden" name="id" value="<?= (int)$i['IDD'] ?>"><button class="link danger">Delete</button></form><?php endif; endif; ?></td>
</tr><?php endforeach; if (!$rows): ?><tr><td colspan="8" class="muted">No issues found.</td></tr><?php endif; ?></tbody></table></div>

<?php
// =====================================================================  CHECK OUT / IN
elseif ($v === 'circ'):
    $avail = ser_available_issues();
    $args = []; $lw = ser_lib_sql('b.Library', $args);
    $st = $pdo->prepare("SELECT b.*, c.Name, c.User_class FROM Borrower b LEFT JOIN Client c ON c.IDnum = b.ID_Number WHERE b.Type = 'Serial' AND b.Date_returned IS NULL$lw ORDER BY b.Due_date");
    $st->execute($args); $out = $st->fetchAll();
    $groups = []; foreach ($avail as $i) $groups[$i['Serial_Title']][] = $i;
?>
<form method="post" class="card form no-print" autocomplete="off">
  <h2 style="margin:0">Check out an issue</h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="checkout">
  <label><span>Borrower ID (scan or type)</span><input type="text" name="idnum" maxlength="30" required autofocus></label>
  <label><span>Scan issue code (SER-12)</span><input type="text" name="code" maxlength="20" placeholder="Scan the issue's code"></label>
  <label><span>…or pick an issue from the list</span><select name="issue"><option value="">— choose —</option>
    <?php foreach ($groups as $t => $items): ?><optgroup label="<?= e($t) ?>"><?php foreach ($items as $i): ?><option value="<?= (int)$i['IDD'] ?>"><?= e(trim(($i['Volume'] !== '' ? 'Vol. ' . $i['Volume'] : '') . ' ' . ($i['Number'] !== '' ? 'No. ' . $i['Number'] : '') . ' ' . ($i['Date_Year'] !== '' ? '(' . $i['Date_Year'] . ')' : ''))) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
    <?php if (!$avail): ?><small class="muted">No received issues are on the shelf.</small><?php endif; ?></label>
  <p class="muted" style="margin:0">Same rules as non-book items: <?= (int)circ_cfg()['nonbook_days'] ?> days, up to <?= (int)circ_cfg()['max_loans'] ?> items per borrower, fine per day when overdue.</p>
  <div class="actions"><button class="btn primary">Check out</button></div>
</form>
<h2>Issues out (<?= count($out) ?>)</h2>
<div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Issue</th><th>Borrower</th><th>Borrowed</th><th>Due</th><th>Status</th><?php if (lib_all()): ?><th>Library</th><?php endif; ?><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($out as $b): $late = $b['Status'] === 'Lost' ? 0 : late_days($b['Due_date']); $fine = $late * fine_rate($b['User_class'] ?? null, is_overnight($b['Library'])); ?>
<tr<?= $late > 0 ? ' class="overdue"' : '' ?>><td><?= e($b['Title']) ?> <small class="muted"><?= e($b['Accession_number']) ?></small></td><td><?= e($b['Name']) ?> <small class="muted">(<?= e($b['ID_Number']) ?>)</small></td>
  <td><?= e(fdate($b['Date_Borrowed'])) ?></td><td><?= e(fdate($b['Due_date'])) ?></td>
  <td><?= $b['Status'] === 'Lost' ? 'Lost' : ($late > 0 ? '<strong class="danger">Overdue</strong> <small>' . $late . ' day' . ($late === 1 ? '' : 's') . ', fine ' . e(money($fine)) . '</small>' : e($b['Status'])) ?></td>
  <?php if (lib_all()): ?><td><?= e($b['Library']) ?></td><?php endif; ?>
  <td class="no-print row-actions">
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="return"><input type="hidden" name="id" value="<?= (int)$b['ID'] ?>">
      <?php if ($fine > 0): ?><label style="font-weight:400"><input type="checkbox" name="paid" value="1"> fine paid</label> <?php endif; ?><button class="link">Check in</button></form>
    <?php if ($b['Status'] !== 'Lost' && $late === 0): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="renew"><input type="hidden" name="id" value="<?= (int)$b['ID'] ?>"><button class="link">Renew</button></form><?php endif; ?>
    <?php if ($b['Status'] !== 'Lost'): ?><form method="post" data-confirm="Mark this issue as lost?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="lost"><input type="hidden" name="id" value="<?= (int)$b['ID'] ?>"><button class="link danger">Lost</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$out): ?><tr><td colspan="<?= lib_all() ? 7 : 6 ?>" class="muted">Nothing is out.</td></tr><?php endif; ?></tbody></table></div>

<?php
// =====================================================================  INVENTORY
else:
    $q = trim($_GET['q'] ?? ''); $lf = $_GET['lib'] ?? ''; $sf = $_GET['s'] ?? ''; $tf = (int)($_GET['t'] ?? 0);
    $where = ['1=1']; $args = [];
    if ($q !== '') { $like = []; foreach (['t.Serial_Title', 's.Code', 's.Volume', 's.Number', 's.Date_Year'] as $i => $c) { $like[] = "$c LIKE :q$i"; $args["q$i"] = "%$q%"; } $where[] = '(' . implode(' OR ', $like) . ')'; }
    $map = ['shelf' => 's.Status = 0 AND s.Date_received IS NOT NULL', 'out' => 's.Status = 1', 'lost' => 's.Status = 2', 'pending' => 's.Status = 0 AND s.Date_received IS NULL'];
    if (isset($map[$sf])) $where[] = $map[$sf];
    if ($tf) { $where[] = 't.ID = :tid'; $args['tid'] = $tf; }
    $w = implode(' AND ', $where) . ser_lib_sql('t.Library', $args, $lf);
    $st = $pdo->prepare("SELECT s.*, t.Serial_Title, t.Library, t.ID AS TitleID FROM SerialsTemp s LEFT JOIN Serial_TitleTemp t ON t.Code = s.Code WHERE $w ORDER BY t.Serial_Title, s.IDD DESC LIMIT 500");
    $st->execute($args); $rows = $st->fetchAll();
    $sum = ['On shelf' => 0, 'Out' => 0, 'Lost' => 0, 'Not received' => 0]; foreach ($rows as $r) $sum[ser_status($r)]++;
    $targs = []; $tl = ser_lib_sql('t.Library', $targs); $titleList = $pdo->prepare("SELECT t.ID, t.Serial_Title FROM Serial_TitleTemp t WHERE 1=1$tl ORDER BY t.Serial_Title"); $titleList->execute($targs); $titleList = $titleList->fetchAll();
?>
<form class="toolbar no-print" method="get"><input type="hidden" name="v" value="inventory">
  <select name="t" aria-label="Title"><option value="">All titles</option><?php foreach ($titleList as $t): ?><option value="<?= (int)$t['ID'] ?>" <?= (int)$t['ID'] === $tf ? 'selected' : '' ?>><?= e($t['Serial_Title']) ?></option><?php endforeach; ?></select>
  <select name="s" aria-label="Status"><option value="">All statuses</option><?php foreach (['shelf' => 'On shelf', 'out' => 'Out', 'lost' => 'Lost', 'pending' => 'Not received'] as $k => $l): ?><option value="<?= $k ?>" <?= $k === $sf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search title, volume, number, date…">
  <?php if (lib_all()): ?><select name="lib" aria-label="Library"><option value="">All libraries</option><?php foreach ($libs as $l): ?><option <?= $l === $lf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn">List</button> <a class="btn ghost" href="<?= e($base . '?v=inventory') ?>">Clear</a> <button type="button" class="btn ghost" data-print>Print</button></form>
<div class="cards"><?php foreach ($sum as $l => $n): ?><div class="card stat"><span class="num"><?= number_format($n) ?></span><span><?= e($l) ?></span></div><?php endforeach; ?></div>
<?php if (count($rows) === 500): ?><p class="muted">Showing the first 500 issues. Narrow the search to see others.</p><?php endif; ?>
<div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Issue code</th><th>Title</th><th>Date / year</th><th>Volume</th><th>Number</th><th>Received</th><th>Status</th><?php if (lib_all()): ?><th>Library</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td>SER-<?= (int)$r['IDD'] ?></td><td><?= e($r['Serial_Title'] ?? '(no matching title: ' . $r['Code'] . ')') ?></td><td><?= e($r['Date_Year']) ?></td><td><?= e($r['Volume']) ?></td><td><?= e($r['Number']) ?></td>
  <td><?= $r['Date_received'] ? e(fdate($r['Date_received'])) : '' ?></td><td><?= e(ser_status($r)) ?></td><?php if (lib_all()): ?><td><?= e($r['Library'] ?? '') ?></td><?php endif; ?></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="<?= lib_all() ? 8 : 7 ?>" class="muted">No issues found.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
