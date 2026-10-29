<?php
// Inventory: scan accession numbers for Books, Audio-Visual, Researches and Serials and record On-shelf / Out / Lost / Missing / Not listed.
//   ?v=scan (default)   ?v=results&c=<count id>   ?v=counts
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
require __DIR__ . '/includes/inventory.php';
$pdo = db();
$pageTitle = 'Inventory';
$base = 'inventory.php';

// The tables come from 06_inventory.sql. Say so clearly if it has not been imported yet.
try { $pdo->query('SELECT 1 FROM inv_count LIMIT 1'); $pdo->query('SELECT 1 FROM inv_scan LIMIT 1'); }
catch (PDOException $ex) {
    include __DIR__ . '/includes/header.php';
    echo '<h1>Inventory</h1><div class="card"><p>The inventory tables are missing. Import <code>06_inventory.sql</code> in phpMyAdmin, then reload this page.</p></div>';
    include __DIR__ . '/includes/footer.php'; exit;
}

$libs = libraries();
$v = $_GET['v'] ?? 'scan';
if (!in_array($v, ['scan', 'results', 'counts'], true)) $v = 'scan';
$ed = can_edit_records();
$formError = '';
$cid = (int)($_GET['c'] ?? $_SESSION['inv_count'] ?? 0);

// ---------- actions (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'start' && $ed) {
        $kind = $_POST['kind'] ?? ''; $lib = lib_all() ? trim($_POST['library'] ?? '') : user_library();
        $name = trim($_POST['name'] ?? '');
        if (!in_array($kind, INV_KINDS, true)) $formError = 'Pick what you are counting.';
        elseif (!inv_allowed_library($lib)) $formError = 'Pick a library.';
        elseif (strlen($name) > 80) $formError = 'The name is too long.';
        else {
            if ($name === '') $name = $kind . ($lib !== '' ? ' - ' . $lib : '') . ' - ' . date('M j, Y');
            $pdo->prepare('INSERT INTO inv_count (Name, Kind, Library, Started_at, Started_by) VALUES (?,?,?,NOW(),?)')->execute([$name, $kind, $lib, $_SESSION['user'] ?? '']);
            $_SESSION['inv_count'] = (int)$pdo->lastInsertId();
            flash('Count started. Scan the first accession number.'); redirect($base);
        }
    } elseif ($do === 'pick') {
        $c = inv_count((int)($_POST['c'] ?? 0));
        if ($c && $c['Closed_at'] === null) $_SESSION['inv_count'] = (int)$c['ID']; else flash('Pick a count that is still open.');
        redirect($base);
    } elseif ($do === 'scan') {
        $c = inv_count((int)($_POST['c'] ?? 0));
        if (!$c) { flash('Not done: count not found.'); redirect($base); }
        [$ok, $msg] = inv_scan($c, (string)($_POST['acc'] ?? ''));
        $_SESSION['inv_last'] = [$ok, $msg];
        redirect($base);
    } elseif ($do === 'loadfile') {
        // "Load file" + "Process": a text/CSV file with one accession number per line (for example from a barcode scanner's memory).
        $c = inv_count((int)($_POST['c'] ?? 0));
        if (!$c || $c['Closed_at'] !== null) { flash('Not done: count not found or already closed.'); redirect($base); }
        $f = $_FILES['accfile'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { flash('Not done: choose a .txt or .csv file first.'); redirect($base); }
        if ($f['size'] > 1048576) { flash('Not done: the file is larger than 1 MB.'); redirect($base); }
        if (!preg_match('/\.(txt|csv)$/i', (string)$f['name'])) { flash('Not done: only .txt or .csv files can be loaded.'); redirect($base); }
        $lines = preg_split('/\R/', (string)file_get_contents($f['tmp_name']));
        $done = 0; $skipped = 0; $bad = []; $seen = [];
        foreach ($lines as $ln) {
            $acc = trim(explode(',', $ln)[0], " \t\"'\xEF\xBB\xBF");
            if ($acc === '' || isset($seen[$acc])) continue;
            $seen[$acc] = 1;
            if (count($seen) > 5000) { $bad[] = 'Stopped after 5000 lines.'; break; }
            [$ok, $msg] = inv_scan($c, $acc);
            if ($ok) $done++; else { $skipped++; if (count($bad) < 5) $bad[] = $acc . ': ' . $msg; }
        }
        flash("File processed: $done recorded, $skipped skipped." . ($bad ? ' ' . implode(' | ', $bad) : ''));
        redirect($base);
    } elseif ($do === 'unscan' && $ed) {
        $c = inv_count((int)($_POST['c'] ?? 0));
        if ($c && $c['Closed_at'] === null) {
            $pdo->prepare('DELETE FROM inv_scan WHERE ID = ? AND Count_ID = ?')->execute([(int)($_POST['id'] ?? 0), $c['ID']]);
            flash('Scan removed.');
        } else flash('Not done: the count is closed or was not found.');
        redirect($base);
    } elseif ($do === 'close' && $ed) {
        $c = inv_count((int)($_POST['c'] ?? 0));
        if (!$c || $c['Closed_at'] !== null) flash('Not done: count not found or already closed.');
        else { $n = inv_close($c); unset($_SESSION['inv_count']); flash("Count closed. $n item" . ($n === 1 ? '' : 's') . ' not found were marked Missing.'); redirect($base . '?v=results&c=' . $c['ID']); }
        redirect($base . '?v=counts');
    } elseif ($do === 'delete' && $ed) {
        $c = inv_count((int)($_POST['c'] ?? 0));
        if ($c) {
            $pdo->prepare('DELETE FROM inv_scan WHERE Count_ID = ?')->execute([$c['ID']]);
            $pdo->prepare('DELETE FROM inv_count WHERE ID = ?')->execute([$c['ID']]);
            if ((int)($_SESSION['inv_count'] ?? 0) === (int)$c['ID']) unset($_SESSION['inv_count']);
            flash('Count deleted.');
        } else flash('Not done: count not found.');
        redirect($base . '?v=counts');
    }
}

// The count being scanned: the one picked this session, only while still open and visible to this user.
$cur = $cid ? inv_count($cid) : null;
if ($v === 'scan' && (!$cur || $cur['Closed_at'] !== null)) { $cur = null; unset($_SESSION['inv_count']); }

include __DIR__ . '/includes/header.php';
echo '<h1>Inventory</h1><p class="tabs no-print">';
foreach (['scan' => 'Scan', 'results' => 'Results', 'counts' => 'Counts'] as $k => $l) echo '<a class="' . ($k === $v ? 'on' : '') . '" href="' . e($base . '?v=' . $k) . '">' . e($l) . '</a>';
echo '</p>';
if ($formError) echo '<div class="err" role="alert">' . e($formError) . '</div>';
$badge = fn($s) => '<span class="pill inv-' . strtolower(preg_replace('/[^A-Za-z0-9-]/', '', str_replace(' ', '', $s))) . '">' . e($s) . '</span>';

// =====================================================================  SCAN
if ($v === 'scan'):
    $last = $_SESSION['inv_last'] ?? null; unset($_SESSION['inv_last']);
    $open = inv_counts(true);
    if ($last && !$cur): ?><div class="<?= $last[0] ? 'flash' : 'err' ?>" role="status"><?= e($last[1]) ?></div><?php endif;
    if ($cur): $sum = inv_summary((int)$cur['ID']); $total = array_sum($sum);
        $rec = $pdo->prepare('SELECT * FROM inv_scan WHERE Count_ID = ? ORDER BY ID DESC LIMIT 15'); $rec->execute([$cur['ID']]); $rec = $rec->fetchAll();
?>
<?php if ($last): ?><div class="<?= $last[0] ? 'flash' : 'err' ?>" role="status"><?= e($last[1]) ?></div><?php endif; ?>
<form method="post" class="card form no-print" autocomplete="off">
  <h2 style="margin:0"><?= e($cur['Name']) ?></h2>
  <p class="muted" style="margin:0"><?= e($cur['Kind']) ?><?= $cur['Library'] !== '' ? ' · ' . e($cur['Library']) : '' ?> · started <?= e(date('M j, Y g:i A', strtotime($cur['Started_at']))) ?> · <strong><?= number_format($total) ?></strong> scanned</p>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="scan"><input type="hidden" name="c" value="<?= (int)$cur['ID'] ?>">
  <label><span><?= $cur['Kind'] === 'Serials' ? 'Issue code (SER-n)' : 'Accession number' ?> (scan or type, then press Enter)</span><input type="text" name="acc" maxlength="60" required autofocus></label>
  <div class="actions"><button class="btn primary">Record</button> <a class="btn ghost" href="<?= e($base . '?v=results&c=' . (int)$cur['ID']) ?>">See results</a></div>
</form>
<form method="post" enctype="multipart/form-data" class="card form no-print">
  <h2 style="margin:0">Load file</h2>
  <p class="muted" style="margin:0">Have a list of scanned numbers saved in a .txt or .csv file (one accession number per line)? Load it here and every line is processed as if it was scanned.</p>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="loadfile"><input type="hidden" name="c" value="<?= (int)$cur['ID'] ?>">
  <label><span>File</span><input type="file" name="accfile" accept=".txt,.csv,text/plain,text/csv" required></label>
  <div class="actions"><button class="btn">Load file and process</button></div>
</form>
<div class="cards"><?php foreach ($sum as $l => $n): ?><div class="card stat"><span class="num"><?= number_format($n) ?></span><span><?= e($l) ?></span></div><?php endforeach; ?></div>
<?php if ($rec): ?>
<section class="card no-print"><h2>Latest scans</h2>
<div class="table-wrap"><table class="data"><thead><tr><th>Time</th><th>Accession</th><th>Title</th><th>Status</th><th>Note</th><th></th></tr></thead><tbody>
<?php foreach ($rec as $r): ?><tr><td><?= e(date('g:i A', strtotime($r['Scanned_at']))) ?></td><td><?= e($r['Accession']) ?></td><td><?= e(shorten($r['Title'], 70)) ?></td><td><?= $badge($r['Status']) ?></td><td><?= e($r['Note']) ?></td>
  <td class="row-actions"><?php if ($ed): ?><form method="post" data-confirm="Remove this scan?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="unscan"><input type="hidden" name="c" value="<?= (int)$cur['ID'] ?>"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link danger">Remove</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
<?php if ($ed): ?>
<form method="post" class="card no-print" data-confirm="Close this count? Every item that should be on the shelf but was not scanned will be marked Missing, and no more scans can be added.">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="close"><input type="hidden" name="c" value="<?= (int)$cur['ID'] ?>">
  <p style="margin-top:0">Finished counting the shelf? Closing marks everything not scanned as <strong>Missing</strong>. Items that are on loan or lost are left alone.</p>
  <button class="btn">Close count and mark Missing</button></form>
<?php endif; ?>
<?php else: /* no open count picked */ ?>
<?php if ($open): ?>
<form method="post" class="card form no-print"><h2 style="margin:0">Continue a count</h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="pick">
  <label><span>Open counts</span><select name="c"><?php foreach ($open as $o): ?><option value="<?= (int)$o['ID'] ?>"><?= e($o['Name']) ?></option><?php endforeach; ?></select></label>
  <div class="actions"><button class="btn primary">Continue</button></div></form>
<?php endif; ?>
<?php if ($ed): ?>
<form method="post" class="card form no-print" autocomplete="off"><h2 style="margin:0">Start a new count</h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="start">
  <label><span>What are you counting?</span><select name="kind" required><option value="">— choose —</option><?php foreach (INV_KINDS as $k): ?><option><?= e($k) ?></option><?php endforeach; ?></select></label>
  <?php if (lib_all()): ?><label><span>Library</span><select name="library"><option value="">Whole collection</option><?php foreach ($libs as $l): ?><option><?= e($l) ?></option><?php endforeach; ?></select></label>
  <?php else: ?><p class="muted" style="margin:0">Library: <?= e(user_library()) ?></p><?php endif; ?>
  <label><span>Name (optional)</span><input type="text" name="name" maxlength="80" placeholder="e.g. Books - College - Oct 2026"></label>
  <p class="muted" style="margin:0">Audio-Visual and Researches are not split by library, so they are counted together. Books are matched by their location (for example “College Library - Circulation”).</p>
  <div class="actions"><button class="btn primary">Start counting</button></div></form>
<?php elseif (!$open): ?>
<div class="card"><p>There is no open count. Ask a librarian or admin to start one.</p></div>
<?php endif; ?>
<?php endif; ?>

<?php
// =====================================================================  RESULTS
elseif ($v === 'results'):
    $all = inv_counts();
    $c = $cid ? inv_count($cid) : null;
    if (!$c && $all) $c = $all[0];
    $sf = $_GET['s'] ?? ''; $q = trim($_GET['q'] ?? ''); $page = max(1, (int)($_GET['p'] ?? 1)); $per = 100;
?>
<form class="toolbar no-print" method="get"><input type="hidden" name="v" value="results">
  <select name="c" aria-label="Count"><?php foreach ($all as $o): ?><option value="<?= (int)$o['ID'] ?>" <?= $c && (int)$o['ID'] === (int)$c['ID'] ? 'selected' : '' ?>><?= e($o['Name']) ?><?= $o['Closed_at'] === null ? ' (open)' : '' ?></option><?php endforeach; ?></select>
  <select name="s" aria-label="Status"><option value="">All statuses</option><?php foreach (array_merge(INV_STATUSES, ['Not yet scanned']) as $s): ?><option <?= $s === $sf ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search accession, title, call number…">
  <button class="btn">List</button> <a class="btn ghost" href="<?= e($base . '?v=results') ?>">Clear</a> <button type="button" class="btn ghost" data-print>Print</button></form>
<?php if (!$c): ?><div class="card"><p>No counts yet. Start one on the Scan tab.</p></div>
<?php else:
    $sum = inv_summary((int)$c['ID']);
    echo '<p class="muted">' . e($c['Name']) . ' · ' . e($c['Kind']) . ($c['Library'] !== '' ? ' · ' . e($c['Library']) : '') . ' · ' . ($c['Closed_at'] === null ? 'open' : 'closed ' . e(date('M j, Y', strtotime($c['Closed_at'])))) . '</p>';
?>
<div class="cards"><?php foreach ($sum as $l => $n): ?><div class="card stat"><span class="num"><?= number_format($n) ?></span><span><?= e($l) ?></span></div><?php endforeach; ?></div>
<?php
    if ($sf === 'Not yet scanned') {
        $args = []; $s = inv_src($c['Kind']);
        $from = inv_unscanned_sql($c, $args, false);
        $cond = ''; if ($q !== '') { $args['q1'] = "%$q%"; $args['q2'] = "%$q%"; $cond = " AND ({$s['acc']} LIKE :q1 OR {$s['title']} LIKE :q2)"; }
        $cnt = $pdo->prepare("SELECT COUNT(*) $from$cond"); $cnt->execute($args); $totalRows = (int)$cnt->fetchColumn();
        $st = $pdo->prepare("SELECT {$s['acc']} AS Accession, {$s['title']} AS Title, {$s['call']} AS Call_Number, 'Not yet scanned' AS Status, '' AS Note, NULL AS Scanned_at $from$cond ORDER BY {$s['acc']} LIMIT " . (int)$per . ' OFFSET ' . (($page - 1) * $per));
        $st->execute($args); $rows = $st->fetchAll();
    } else {
        $where = ['Count_ID = :c']; $args = ['c' => $c['ID']];
        if (in_array($sf, INV_STATUSES, true)) { $where[] = 'Status = :s'; $args['s'] = $sf; }
        if ($q !== '') { $where[] = '(Accession LIKE :q1 OR Title LIKE :q2 OR Call_Number LIKE :q3)'; $args['q1'] = $args['q2'] = $args['q3'] = "%$q%"; }
        $w = implode(' AND ', $where);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM inv_scan WHERE $w"); $cnt->execute($args); $totalRows = (int)$cnt->fetchColumn();
        $st = $pdo->prepare("SELECT * FROM inv_scan WHERE $w ORDER BY Accession LIMIT " . (int)$per . ' OFFSET ' . (($page - 1) * $per));
        $st->execute($args); $rows = $st->fetchAll();
    }
    $pages = max(1, (int)ceil($totalRows / $per));
?>
<div class="table-wrap"><table class="data" data-sortable><thead><tr><th>Accession</th><th>Title</th><th>Call number</th><th>Status</th><th>Scanned</th><th>Note</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['Accession']) ?></td><td><?= e(shorten($r['Title'], 90)) ?></td><td><?= e($r['Call_Number']) ?></td>
  <td><?= $r['Status'] === 'Not yet scanned' ? '<span class="pill">Not yet scanned</span>' : $badge($r['Status']) ?></td><td><?= $r['Scanned_at'] ? e(date('M j, Y g:i A', strtotime($r['Scanned_at']))) : '' ?></td><td><?= e($r['Note']) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="muted">Nothing to show.</td></tr><?php endif; ?></tbody></table></div>
<?php $qs = ['v' => 'results', 'c' => $c['ID'], 's' => $sf, 'q' => $q]; ?>
<p class="muted no-print"><?= number_format($totalRows) ?> row<?= $totalRows === 1 ? '' : 's' ?><?= $pages > 1 ? " · page $page of $pages" : '' ?>
  <?php if ($page > 1): ?> <a href="<?= e($base . '?' . http_build_query($qs + ['p' => $page - 1])) ?>">‹ Previous</a><?php endif; ?>
  <?php if ($page < $pages): ?> <a href="<?= e($base . '?' . http_build_query($qs + ['p' => $page + 1])) ?>">Next ›</a><?php endif; ?></p>
<?php endif; ?>

<?php
// =====================================================================  COUNTS
else:
    $all = inv_counts();
?>
<div class="table-wrap"><table class="data"><thead><tr><th>Name</th><th>Kind</th><th>Library</th><th>Started</th><th>Status</th><th>Scanned</th><th>Missing</th><th></th></tr></thead><tbody>
<?php foreach ($all as $o): $sm = inv_summary((int)$o['ID']); ?><tr>
  <td><a href="<?= e($base . '?v=results&c=' . (int)$o['ID']) ?>"><?= e($o['Name']) ?></a></td><td><?= e($o['Kind']) ?></td><td><?= e($o['Library'] ?: 'Whole collection') ?></td>
  <td><?= e(date('M j, Y', strtotime($o['Started_at']))) ?></td><td><?= $o['Closed_at'] === null ? 'Open' : 'Closed ' . e(date('M j, Y', strtotime($o['Closed_at']))) ?></td>
  <td><?= number_format(array_sum($sm) - $sm['Missing']) ?></td><td><?= number_format($sm['Missing']) ?></td>
  <td class="row-actions"><?php if ($o['Closed_at'] === null): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="pick"><input type="hidden" name="c" value="<?= (int)$o['ID'] ?>"><button class="link">Scan</button></form><?php endif; ?>
    <?php if ($ed): ?><form method="post" style="display:inline" data-confirm="Delete this count and all its scans?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="c" value="<?= (int)$o['ID'] ?>"><button class="link danger">Delete</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$all): ?><tr><td colspan="8" class="muted">No counts yet.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
