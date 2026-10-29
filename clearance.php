<?php
// Clearance: confirms a borrower has nothing unreturned or unpaid, records it, and prints a clearance slip.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db(); $pageTitle = 'Clearance';
$have = table_exists('client_clearance');
$by = $_SESSION['name'] ?? ($_SESSION['user'] ?? '');
[, $signatory] = ce_notice_text();

$idnum = trim($_GET['idnum'] ?? ($_POST['idnum'] ?? '')); $q = trim($_GET['q'] ?? '');
$client = $idnum !== '' ? client_by_id($idnum) : null; $found = [];
if (!$client && $q !== '') { $found = ce_find_clients($q); if (count($found) === 1) { $client = client_by_id($found[0]['IDnum']); $idnum = $client['IDnum']; } }

function clearance_blockers(string $idnum): array {   // what stops this borrower from being cleared
    $pdo = db(); $b = [];
    $st = $pdo->prepare('SELECT Title, Accession_number, Due_date, Status FROM Borrower WHERE ID_Number = ? AND Date_returned IS NULL'); $st->execute([$idnum]);
    foreach ($st->fetchAll() as $r) $b[] = ($r['Status'] === 'Lost' ? 'Lost item not settled: ' : 'Not yet returned: ') . shorten($r['Title'], 60) . ' (' . $r['Accession_number'] . ')';
    if (function_exists('ce_unpaid_total') && ce_have_balance() && ($t = ce_unpaid_total($idnum)) > 0) $b[] = 'Unpaid balance: ' . number_format($t, 2);
    if (table_exists('equipment_loan')) { $st = $pdo->prepare("SELECT Equipment FROM equipment_loan WHERE ID_Number = ? AND Status = 'Out'"); $st->execute([$idnum]); foreach ($st->fetchAll() as $r) $b[] = 'Equipment not returned: ' . $r['Equipment']; }
    return $b;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $have && $client) {
    csrf_check();
    $term = substr(trim((string)($_POST['term'] ?? '')), 0, 60); $note = substr(trim((string)($_POST['note'] ?? '')), 0, 150);
    if (clearance_blockers($client['IDnum'])) flash('Not cleared: this borrower still has items or payments to settle.');
    else {
        $pdo->prepare('INSERT INTO client_clearance (ID_Number, Name, Term, Cleared_at, Cleared_by, Note) VALUES (?,?,?,NOW(),?,?)')->execute([$client['IDnum'], $client['Name'], $term !== '' ? $term : null, $by, $note !== '' ? $note : null]);
        flash('Cleared. You can print the slip below.');
        redirect('clearance.php?idnum=' . rawurlencode($client['IDnum']) . '&slip=' . (int)$pdo->lastInsertId());
    }
    redirect('clearance.php?idnum=' . rawurlencode($client['IDnum']));
}
$slip = null;
if ($have && $client && isset($_GET['slip'])) { $st = $pdo->prepare('SELECT * FROM client_clearance WHERE ID = ? AND ID_Number = ?'); $st->execute([(int)$_GET['slip'], $client['IDnum']]); $slip = $st->fetch() ?: null; }
$blockers = $client ? clearance_blockers($client['IDnum']) : [];
$hist = []; if ($have && $client) { $st = $pdo->prepare('SELECT * FROM client_clearance WHERE ID_Number = ? ORDER BY Cleared_at DESC LIMIT 10'); $st->execute([$client['IDnum']]); $hist = $st->fetchAll(); }
include __DIR__ . '/includes/header.php'; ?>
<h1>Clearance</h1>
<?php if (!$have): ?><div class="err">Import 13_opac_and_extras.sql in phpMyAdmin first, then reload this page.</div><?php else: ?>
<p class="muted no-print">Check that a borrower has no unreturned items, unsettled losses, unpaid balance or equipment out. If everything is settled, clear them and print the slip.</p>
<form class="toolbar no-print" method="get"><input type="search" name="q" value="<?= e($q ?: $idnum) ?>" placeholder="ID number or name" aria-label="Find borrower" autofocus><button class="btn">Find</button></form>
<?php if (!$client && $found): ?><div class="table-wrap no-print"><table class="data"><tbody><?php foreach ($found as $f): ?><tr><td><a href="clearance.php?idnum=<?= e(urlencode($f['IDnum'])) ?>"><?= e($f['Name']) ?></a></td><td><?= e($f['IDnum']) ?></td><td><?= e($f['User_class']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php elseif ($q !== '' && !$client): ?><p class="muted">No borrower found.</p><?php endif; ?>
<?php if ($client): ?>
 <section class="card no-print"><h2 style="margin-top:0"><?= e($client['Name']) ?> <small class="muted"><?= e($client['IDnum']) ?> · <?= e($client['Degree_Course']) ?> <?= e($client['Year_Level']) ?></small></h2>
  <?php if ($blockers): ?><p><b>Not ready for clearance.</b> Settle these first:</p><ul><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
   <p><a href="circulation.php?idnum=<?= e(urlencode($client['IDnum'])) ?>">Open circulation</a> · <a href="balance.php?idnum=<?= e(urlencode($client['IDnum'])) ?>">Balance &amp; payments</a></p>
  <?php else: ?><p><b>Nothing outstanding.</b> This borrower can be cleared.</p>
   <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="idnum" value="<?= e($client['IDnum']) ?>">
    <label><span>Term or school year</span><input name="term" maxlength="60" placeholder="e.g. 1st Semester 2026-2027"></label><label><span>Note (optional)</span><input name="note" maxlength="150"></label>
    <div class="actions"><button class="btn primary">Clear this borrower</button></div></form><?php endif; ?></section>
 <?php if ($slip): ?>
 <section class="card" style="max-width:640px;margin:12px auto;padding:26px">
  <div style="text-align:center"><b style="font-size:1.15rem">La Consolacion College Tanauan</b><br>St. Augustine Learning Resource Center<h2 style="margin:14px 0 4px;letter-spacing:.08em">LIBRARY CLEARANCE</h2></div>
  <p>This is to certify that <b><?= e($slip['Name']) ?></b> (ID number <?= e($slip['ID_Number']) ?><?= $client['Degree_Course'] ? ', ' . e($client['Degree_Course']) . ' ' . e($client['Year_Level']) : '' ?>) has no unreturned library materials and no unpaid library obligations<?= $slip['Term'] ? ' for <b>' . e($slip['Term']) . '</b>' : '' ?>.</p>
  <?php if ($slip['Note']): ?><p><?= e($slip['Note']) ?></p><?php endif; ?>
  <p>Date cleared: <?= e(fdate($slip['Cleared_at'])) ?></p>
  <div style="margin-top:44px;text-align:right"><div style="display:inline-block;min-width:220px;border-top:1px solid #000;text-align:center;padding-top:4px"><?= e($signatory !== '' ? $signatory : $slip['Cleared_by']) ?><br><small>Librarian</small></div></div>
  <p class="no-print" style="text-align:center;margin-top:18px"><button type="button" class="btn primary" data-print>Print slip</button></p></section>
 <?php endif; ?>
 <?php if ($hist): ?><section class="card no-print"><h2 style="margin-top:0">Earlier clearances</h2><div class="table-wrap"><table class="data"><tbody><?php foreach ($hist as $h): ?><tr><td><?= e(fdate($h['Cleared_at'])) ?></td><td><?= e($h['Term']) ?></td><td><?= e($h['Cleared_by']) ?></td><td><a href="clearance.php?idnum=<?= e(urlencode($client['IDnum'])) ?>&slip=<?= (int)$h['ID'] ?>">Show slip</a></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<?php endif; endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
