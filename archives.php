<?php
// Archives: catalog records that were deleted (lost, missing, withdrawn, discarded…). Searchable, printable, and a record can be restored.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';   // date helpers
if (!can_edit_records()) deny();
$pdo = db();
$pageTitle = 'Archives';
if (!table_exists('catalog_archive')) { include __DIR__ . '/includes/header.php'; echo '<h1>Archives</h1><div class="err">Import 13_opac_and_extras.sql in phpMyAdmin first, then reload this page.</div>'; include __DIR__ . '/includes/footer.php'; exit; }
$reasons = ['Withdrawn', 'Lost', 'Missing', 'Discarded', 'Damaged', 'Other'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM catalog_archive WHERE ID = ?'); $st->execute([$id]); $a = $st->fetch();
    if (($_POST['do'] ?? '') === 'restore' && $a) {
        $tbl = $a['Kind'] === 'Books' ? 'AcList' : 'AccSM';
        $dup = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` WHERE Accession = ?"); $dup->execute([$a['Accession']]);
        if ((int)$dup->fetchColumn()) flash('Not restored: that accession number is already in the catalog.');
        else {
            $cols = $a['Kind'] === 'Books' ? ['Accession', 'Title', 'Author', 'Publisher', 'Call_Number', 'ISBN', 'Ye_ar', 'Location'] : ['Accession', 'Title', 'Author', 'Publisher', 'Call_Number', 'Ye_ar', 'Type', 'Location'];
            $pdo->prepare("INSERT INTO `$tbl` (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_map(fn($c) => $a[$c], $cols));
            $pdo->prepare('DELETE FROM catalog_archive WHERE ID = ?')->execute([$id]);
            flash('Restored to the catalog: ' . shorten($a['Title'], 60));
        }
    } elseif (($_POST['do'] ?? '') === 'purge' && $a && ($_SESSION['role'] ?? '') === 'admin') {
        $pdo->prepare('DELETE FROM catalog_archive WHERE ID = ?')->execute([$id]); flash('Archive entry removed for good.');
    }
    redirect('archives.php?' . http_build_query(array_intersect_key($_POST, array_flip(['q', 'reason', 'kind', 'from', 'to']))));
}
$q = trim($_GET['q'] ?? ''); $rs = $_GET['reason'] ?? ''; $kind = $_GET['kind'] ?? '';
$from = valid_date($_GET['from'] ?? '', ''); $to = valid_date($_GET['to'] ?? '', '');
$w = ['1=1']; $args = [];
if ($q !== '') { $w[] = '(Accession LIKE :q OR Title LIKE :q2 OR Author LIKE :q3 OR Call_Number LIKE :q4)'; foreach (['q', 'q2', 'q3', 'q4'] as $k) $args[$k] = "%$q%"; }
if (in_array($rs, $reasons, true)) { $w[] = 'Reason = :r'; $args['r'] = $rs; }
if (in_array($kind, ['Books', 'Non-book'], true)) { $w[] = 'Kind = :k'; $args['k'] = $kind; }
if ($from !== '') { $w[] = 'DATE(Deleted_at) >= :f'; $args['f'] = $from; }
if ($to !== '') { $w[] = 'DATE(Deleted_at) <= :t'; $args['t'] = $to; }
$st = $pdo->prepare('SELECT * FROM catalog_archive WHERE ' . implode(' AND ', $w) . ' ORDER BY Deleted_at DESC, ID DESC LIMIT 500'); $st->execute($args); $rows = $st->fetchAll();
$byReason = []; foreach ($rows as $r) $byReason[$r['Reason']] = ($byReason[$r['Reason']] ?? 0) + 1;
include __DIR__ . '/includes/header.php'; ?>
<h1>Archives</h1>
<p class="muted">Books and non-book records that were removed from the catalog. Choose a reason when you delete a record and it is kept here.</p>
<form class="toolbar no-print" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Accession, title, author, call number…">
  <select name="kind" aria-label="Kind"><option value="">Books and non-book</option><option <?= $kind === 'Books' ? 'selected' : '' ?>>Books</option><option <?= $kind === 'Non-book' ? 'selected' : '' ?>>Non-book</option></select>
  <select name="reason" aria-label="Reason"><option value="">Any reason</option><?php foreach ($reasons as $x): ?><option <?= $rs === $x ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select>
  <input type="date" name="from" value="<?= e($from) ?>" aria-label="Removed from"> <input type="date" name="to" value="<?= e($to) ?>" aria-label="Removed to">
  <button class="btn">Show</button> <button type="button" class="btn ghost" data-print>Print</button></form>
<p class="muted"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?><?php if ($byReason): ?> · <?php foreach ($byReason as $k => $n) echo e("$k $n") . '  '; endif; ?></p>
<div class="table-wrap"><table class="data"><thead><tr><th>Removed</th><th>Accession</th><th>Title</th><th>Author</th><th>Call number</th><th>Reason</th><th>By</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e(fdate($r['Deleted_at'])) ?></td><td><?= e($r['Accession']) ?></td><td><?= e(shorten($r['Title'], 70)) ?><?= $r['Kind'] === 'Non-book' ? ' <small class="muted">(non-book)</small>' : '' ?></td><td><?= e($r['Author']) ?></td><td><?= e($r['Call_Number']) ?></td><td><?= e($r['Reason']) ?></td><td><?= e($r['Deleted_by']) ?></td>
 <td class="row-actions no-print"><form method="post" style="display:inline" data-confirm="Put this record back in the catalog?"><?= csrf_field() ?><input type="hidden" name="do" value="restore"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link">Restore</button></form>
 <?php if (($_SESSION['role'] ?? '') === 'admin'): ?><form method="post" style="display:inline" data-confirm="Remove this archive entry for good?"><?= csrf_field() ?><input type="hidden" name="do" value="purge"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link danger">Remove</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8" class="muted">Nothing archived yet.</td></tr><?php endif; ?></tbody></table></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
