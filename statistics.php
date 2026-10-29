<?php
// Statistics: the collection by Dewey (DDC) class, loan statistics (by class, borrower type, audio-visual type), and saved ("backup") copies of past loan statistics.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
if (!can_edit_records()) deny();
$pdo = db(); $pageTitle = 'Statistics';
$DDC = ['000' => 'General works', '100' => 'Philosophy & psychology', '200' => 'Religion', '300' => 'Social sciences', '400' => 'Language', '500' => 'Natural sciences & mathematics',
        '600' => 'Technology', '700' => 'Arts & recreation', '800' => 'Literature', '900' => 'History & geography'];
function ddc_key(?string $call, string $level): string {
    if (!preg_match('/(?<!\d)(\d{3})(?:\.\d+)?(?!\d)/', (string)$call, $m)) return 'none';
    $n = (int)$m[1]; return sprintf('%03d', $level === 'tens' ? intdiv($n, 10) * 10 : intdiv($n, 100) * 100);
}
$level = ($_GET['level'] ?? '') === 'tens' ? 'tens' : 'hundreds';
$v = $_GET['v'] ?? 'ddc'; if (!in_array($v, ['ddc', 'loans', 'saved'], true)) $v = 'ddc';
$kinds = ['DDC' => 'Loans by Dewey class', 'User' => 'Loans by borrower type', 'AV' => 'Loans of audio-visual materials'];
$today = date('Y-m-d');
$to = ce_date($_GET['to'] ?? ($_POST['to'] ?? ''), $today);
$from = ce_date($_GET['from'] ?? ($_POST['from'] ?? ''), date('Y-m-01', strtotime($to)));
if ($from > $to) [$from, $to] = [$to, $from];
$lf = trim($_GET['lib'] ?? ($_POST['lib'] ?? '')); $kind = $_GET['kind'] ?? ($_POST['kind'] ?? 'DDC'); if (!isset($kinds[$kind])) $kind = 'DDC';
$haveBk = table_exists('loan_stat_backup');

// Loan statistics for the chosen kind -> [columns, rows]
function loan_stats(PDO $pdo, string $kind, string $from, string $to, string $lf, string $level, array $DDC): array {
    $args = ['f' => $from, 't' => $to]; $lw = ce_lib_where('b.Library', $args, $lf);
    $w = "b.Date_Borrowed IS NOT NULL AND DATE(b.Date_Borrowed) BETWEEN :f AND :t" . $lw;
    if ($kind === 'User') {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(c.User_class, ''), 'Not stated') cls, COUNT(*) n, COUNT(DISTINCT b.ID_Number) people FROM Borrower b LEFT JOIN Client c ON c.IDnum = b.ID_Number WHERE $w GROUP BY cls ORDER BY n DESC");
        $st->execute($args); $rows = $st->fetchAll(); $tot = array_sum(array_column($rows, 'n'));
        return [['Borrower type', 'Loans', 'Different borrowers', '% of loans'], array_map(fn($r) => [$r['cls'], (int)$r['n'], (int)$r['people'], $tot ? round($r['n'] / $tot * 100, 1) : 0], $rows)];
    }
    if ($kind === 'AV') {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(s.Type, ''), 'Not stated') ty, COUNT(*) n FROM Borrower b JOIN AccSM s ON s.Accession = b.Accession_number WHERE $w GROUP BY ty ORDER BY n DESC");
        $st->execute($args); $rows = $st->fetchAll(); $tot = array_sum(array_column($rows, 'n'));
        return [['Type of material', 'Loans', '% of loans'], array_map(fn($r) => [$r['ty'], (int)$r['n'], $tot ? round($r['n'] / $tot * 100, 1) : 0], $rows)];
    }
    $st = $pdo->prepare("SELECT a.Call_Number cn, COUNT(*) n FROM Borrower b JOIN AcList a ON a.Accession = b.Accession_number WHERE $w GROUP BY a.Call_Number");
    $st->execute($args); $b = [];
    foreach ($st->fetchAll() as $r) { $k = ddc_key($r['cn'], $level); $b[$k] = ($b[$k] ?? 0) + (int)$r['n']; }
    ksort($b); $tot = array_sum($b); $out = [];
    foreach ($b as $k => $n) $out[] = [$k === 'none' ? 'No Dewey number' : $k . ($level === 'hundreds' ? ' ' . ($DDC[$k] ?? '') : ''), $n, $tot ? round($n / $tot * 100, 1) : 0];
    return [['Class', 'Loans', '% of loans'], $out];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $do = $_POST['do'] ?? '';
    if ($do === 'save' && $haveBk) {
        [$cols, $rows] = loan_stats($pdo, $kind, $from, $to, $lf, $level, $DDC);
        $pdo->prepare('INSERT INTO loan_stat_backup (Kind, Date_from, Date_to, Library, Saved_at, Saved_by, Data) VALUES (?,?,?,?,NOW(),?,?)')
            ->execute([$kind, $from, $to, $lf !== '' ? $lf : null, $_SESSION['name'] ?? ($_SESSION['user'] ?? ''), json_encode(['cols' => $cols, 'rows' => $rows], JSON_UNESCAPED_UNICODE)]);
        flash('Saved. You can open it again under "Saved statistics".'); redirect('statistics.php?v=saved');
    }
    if ($do === 'delete' && $haveBk && ($_SESSION['role'] ?? '') === 'admin') { $pdo->prepare('DELETE FROM loan_stat_backup WHERE ID = ?')->execute([(int)($_POST['id'] ?? 0)]); flash('Saved statistic removed.'); redirect('statistics.php?v=saved'); }
    redirect('statistics.php');
}
$tabUrl = fn($t) => 'statistics.php?v=' . $t;
include __DIR__ . '/includes/header.php'; ?>
<h1>Statistics</h1>
<p class="tabs no-print"><a class="<?= $v === 'ddc' ? 'on' : '' ?>" href="<?= e($tabUrl('ddc')) ?>">Collection by Dewey class</a><a class="<?= $v === 'loans' ? 'on' : '' ?>" href="<?= e($tabUrl('loans')) ?>">Loan statistics</a><a class="<?= $v === 'saved' ? 'on' : '' ?>" href="<?= e($tabUrl('saved')) ?>">Saved statistics</a></p>

<?php if ($v === 'ddc'):
  $st = $pdo->query('SELECT MIN(Call_Number) cn, COUNT(*) vols FROM AcList GROUP BY LEFT(Title, 200), Author, Call_Number'); $titles = []; $vols = [];
  foreach ($st->fetchAll() as $r) { $k = ddc_key($r['cn'], $level); $titles[$k] = ($titles[$k] ?? 0) + 1; $vols[$k] = ($vols[$k] ?? 0) + (int)$r['vols']; }
  ksort($titles); $tt = array_sum($titles); $tv = array_sum($vols); $maxT = max(1, ...array_values($titles ?: [1])); ?>
  <p class="muted">Books by Dewey Decimal class. A title counts once however many copies (volumes) the library has.</p>
  <form class="toolbar no-print" method="get"><input type="hidden" name="v" value="ddc"><select name="level" aria-label="Level"><option value="hundreds" <?= $level === 'hundreds' ? 'selected' : '' ?>>Main classes (100s)</option><option value="tens" <?= $level === 'tens' ? 'selected' : '' ?>>Divisions (10s)</option></select><button class="btn">Show</button> <button type="button" class="btn ghost" data-print>Print</button></form>
  <div class="table-wrap"><table class="data"><thead><tr><th>Class</th><th>Titles</th><th>% of titles</th><th>Volumes</th><th>% of volumes</th><th class="no-print"></th></tr></thead><tbody>
  <?php foreach ($titles as $k => $n): ?><tr><td><?= e($k === 'none' ? 'No Dewey number' : $k . ($level === 'hundreds' ? ' ' . ($DDC[$k] ?? '') : '')) ?></td><td><?= number_format($n) ?></td><td><?= $tt ? round($n / $tt * 100, 1) : 0 ?>%</td><td><?= number_format($vols[$k]) ?></td><td><?= $tv ? round($vols[$k] / $tv * 100, 1) : 0 ?>%</td><td class="no-print" style="width:25%"><span class="bar"><i style="width:<?= round($n / $maxT * 100) ?>%"></i></span></td></tr><?php endforeach; ?>
  <tr><th>Total</th><th><?= number_format($tt) ?></th><th>100%</th><th><?= number_format($tv) ?></th><th>100%</th><th class="no-print"></th></tr></tbody></table></div>

<?php elseif ($v === 'loans'): [$cols, $rows] = loan_stats($pdo, $kind, $from, $to, $lf, $level, $DDC); ?>
  <form class="toolbar no-print" method="get"><input type="hidden" name="v" value="loans">
   <select name="kind" aria-label="Statistic"><?php foreach ($kinds as $k => $l): ?><option value="<?= e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
   <?php if ($kind === 'DDC'): ?><select name="level" aria-label="Level"><option value="hundreds" <?= $level === 'hundreds' ? 'selected' : '' ?>>Main classes</option><option value="tens" <?= $level === 'tens' ? 'selected' : '' ?>>Divisions</option></select><?php endif; ?>
   <input type="date" name="from" value="<?= e($from) ?>" aria-label="From"> <input type="date" name="to" value="<?= e($to) ?>" aria-label="To"><?= ce_lib_picker($lf) ?>
   <button class="btn">Show</button> <button type="button" class="btn ghost" data-print>Print</button></form>
  <h2 style="margin:6px 0"><?= e($kinds[$kind]) ?></h2><p class="muted"><?= e(fdate($from)) ?> to <?= e(fdate($to)) ?><?= $lf !== '' ? ' · ' . e($lf) : '' ?></p>
  <div class="table-wrap"><table class="data"><thead><tr><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><?php foreach ($r as $i => $c): ?><td><?= e(is_float($c) || ($i === count($r) - 1 && is_numeric($c) && stripos($cols[$i], '%') !== false) ? $c . '%' : $c) ?></td><?php endforeach; ?></tr><?php endforeach; if (!$rows): ?><tr><td colspan="<?= count($cols) ?>" class="muted">No loans in this period.</td></tr><?php endif; ?></tbody></table></div>
  <?php if ($haveBk && $rows): ?><form method="post" class="no-print"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><input type="hidden" name="lib" value="<?= e($lf) ?>"><input type="hidden" name="level" value="<?= e($level) ?>"><button class="btn">Save a copy of this statistic</button></form>
  <?php elseif (!$haveBk): ?><p class="muted no-print">Import 13_opac_and_extras.sql to be able to save copies of statistics.</p><?php endif; ?>

<?php else: // saved ?>
  <?php if (!$haveBk): ?><div class="err">Import 13_opac_and_extras.sql in phpMyAdmin first, then reload this page.</div><?php else:
   $sid = (int)($_GET['id'] ?? 0);
   if ($sid) { $st = $pdo->prepare('SELECT * FROM loan_stat_backup WHERE ID = ?'); $st->execute([$sid]); $one = $st->fetch(); }
   if (!empty($one)): $d = json_decode($one['Data'], true) ?: ['cols' => [], 'rows' => []]; ?>
    <p class="no-print"><a href="statistics.php?v=saved">‹ All saved statistics</a> <button type="button" class="btn ghost" data-print>Print</button></p>
    <h2 style="margin:6px 0"><?= e($kinds[$one['Kind']] ?? $one['Kind']) ?></h2><p class="muted"><?= e(fdate($one['Date_from'])) ?> to <?= e(fdate($one['Date_to'])) ?><?= $one['Library'] ? ' · ' . e($one['Library']) : '' ?> · saved <?= e(fdatetime($one['Saved_at'])) ?> by <?= e($one['Saved_by']) ?></p>
    <div class="table-wrap"><table class="data"><thead><tr><?php foreach ($d['cols'] as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($d['rows'] as $r): ?><tr><?php foreach ($r as $i => $c): ?><td><?= e(stripos($d['cols'][$i] ?? '', '%') !== false ? $c . '%' : $c) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
   <?php else: $list = $pdo->query('SELECT ID, Kind, Date_from, Date_to, Library, Saved_at, Saved_by FROM loan_stat_backup ORDER BY Saved_at DESC LIMIT 200')->fetchAll(); ?>
    <p class="muted">Copies of loan statistics you saved, so the numbers stay as they were even if records change later.</p>
    <div class="table-wrap"><table class="data"><thead><tr><th>Saved</th><th>Statistic</th><th>Period</th><th>Library</th><th>By</th><th class="no-print"></th></tr></thead><tbody>
    <?php foreach ($list as $r): ?><tr><td><?= e(fdatetime($r['Saved_at'])) ?></td><td><?= e($kinds[$r['Kind']] ?? $r['Kind']) ?></td><td><?= e(fdate($r['Date_from'])) ?> to <?= e(fdate($r['Date_to'])) ?></td><td><?= e($r['Library'] ?: 'All') ?></td><td><?= e($r['Saved_by']) ?></td>
     <td class="row-actions no-print"><a href="statistics.php?v=saved&id=<?= (int)$r['ID'] ?>">Open</a><?php if (($_SESSION['role'] ?? '') === 'admin'): ?> <form method="post" style="display:inline" data-confirm="Remove this saved statistic?"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link danger">Remove</button></form><?php endif; ?></td></tr>
    <?php endforeach; if (!$list): ?><tr><td colspan="6" class="muted">Nothing saved yet. Open Loan statistics and press "Save a copy".</td></tr><?php endif; ?></tbody></table></div>
   <?php endif; endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
