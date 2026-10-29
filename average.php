<?php
// Average of clientele who borrowed (the old "Avg" button): how many different borrowers checked out materials, per day and per hour.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db();
$pageTitle = 'Average borrowers';
$today = date('Y-m-d');
$to = ce_date($_GET['to'] ?? '', $today);
$from = ce_date($_GET['from'] ?? '', date('Y-m-d', strtotime('-29 days', strtotime($to))));
if ($from > $to) [$from, $to] = [$to, $from];
$lf = trim($_GET['lib'] ?? '');
$days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;

$args = ['f' => $from, 't' => $to]; $lw = ce_lib_where('b.Library', $args, $lf);
$w = "b.Date_Borrowed IS NOT NULL AND DATE(b.Date_Borrowed) BETWEEN :f AND :t" . $lw;
$st = $pdo->prepare("SELECT DATE(b.Date_Borrowed) d, COUNT(DISTINCT b.ID_Number) borrowers, COUNT(*) loans FROM Borrower b WHERE $w GROUP BY DATE(b.Date_Borrowed) ORDER BY d");
$st->execute($args); $perDay = $st->fetchAll();
$st = $pdo->prepare("SELECT HOUR(b.Date_Borrowed) h, COUNT(DISTINCT CONCAT(DATE(b.Date_Borrowed), '|', b.ID_Number)) borrowers, COUNT(*) loans FROM Borrower b WHERE $w GROUP BY HOUR(b.Date_Borrowed) ORDER BY h");
$st->execute($args); $perHour = []; foreach ($st->fetchAll() as $r) $perHour[(int)$r['h']] = $r;

$active = count($perDay); $sumB = array_sum(array_map(fn($r) => (int)$r['borrowers'], $perDay)); $sumL = array_sum(array_map(fn($r) => (int)$r['loans'], $perDay));
$avgActive = $active ? $sumB / $active : 0; $avgCal = $sumB / max(1, $days);
$maxDay = max(1, ...array_map(fn($r) => (int)$r['borrowers'], $perDay ?: [['borrowers' => 1]]));
$hours = array_keys($perHour) ?: [8]; $h0 = max(0, min($hours) ); $h1 = min(23, max($hours));
$maxHour = max(1, ...array_map(fn($r) => (int)$r['borrowers'], $perHour ?: [['borrowers' => 1]]));
$n1 = fn($x) => number_format((float)$x, 1);

include __DIR__ . '/includes/header.php'; ?>
<h1>Average borrowers</h1>
<p class="muted">How many different borrowers checked out materials. A borrower counts once per day, however many items they took.</p>
<form class="toolbar no-print" method="get">
  <input type="date" name="from" value="<?= e($from) ?>" aria-label="From"> <input type="date" name="to" value="<?= e($to) ?>" aria-label="To">
  <?= ce_lib_picker($lf) ?>
  <button class="btn">Show</button> <button type="button" class="btn ghost" data-print>Print</button></form>
<p class="muted"><?= e(fdate($from)) ?> to <?= e(fdate($to)) ?> · <?= $days ?> day<?= $days === 1 ? '' : 's' ?></p>
<div class="cards">
  <div class="card stat"><span class="num"><?= $n1($avgActive) ?></span><span>Average borrowers per day (days with loans)</span></div>
  <div class="card stat"><span class="num"><?= $n1($avgCal) ?></span><span>Average per calendar day</span></div>
  <div class="card stat"><span class="num"><?= number_format($sumB) ?></span><span>Borrower-days in total</span></div>
  <div class="card stat"><span class="num"><?= number_format($sumL) ?></span><span>Items checked out</span></div>
</div>
<section class="card"><h2>Average per hour</h2>
<p class="muted" style="margin-top:0">Average number of borrowers in each hour, over all <?= $days ?> day<?= $days === 1 ? '' : 's' ?>.</p>
<?php if ($perHour): for ($h = $h0; $h <= $h1; $h++): $r = $perHour[$h] ?? ['borrowers' => 0, 'loans' => 0]; ?>
<div class="bar-row"><span class="bar-label"><?= e(date('g:00 A', strtotime(sprintf('%02d:00', $h)))) ?></span><span class="bar"><i style="width:<?= round($r['borrowers'] / $maxHour * 100) ?>%"></i></span><span class="bar-n"><?= $n1($r['borrowers'] / max(1, $days)) ?> avg · <?= number_format($r['borrowers']) ?> total</span></div>
<?php endfor; else: ?><p class="muted">No loans in this period.</p><?php endif; ?></section>
<section class="card"><h2>Borrowers per day</h2>
<?php foreach ($perDay as $r): ?><div class="bar-row"><span class="bar-label"><?= e(date('M j', strtotime($r['d']))) ?></span><span class="bar"><i style="width:<?= round($r['borrowers'] / $maxDay * 100) ?>%"></i></span><span class="bar-n"><?= number_format($r['borrowers']) ?> · <?= number_format($r['loans']) ?> item<?= (int)$r['loans'] === 1 ? '' : 's' ?></span></div>
<?php endforeach; if (!$perDay): ?><p class="muted">No loans in this period.</p><?php endif; ?></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
