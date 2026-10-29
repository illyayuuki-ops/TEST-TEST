<?php
require __DIR__ . '/includes/bootstrap.php';
$pageTitle = 'Dashboard';
$q = fn($sql) => (int) db()->query($sql)->fetchColumn();
$stats = [
    ['Books', $q('SELECT COUNT(*) FROM AcList'), 'crud.php?e=books'],
    ['Acquisition records', $q('SELECT COUNT(*) FROM acquitemp'), 'crud.php?e=acquisitions'],
    ['Non-book items', $q('SELECT COUNT(*) FROM AccSM'), 'crud.php?e=nonbook'],
    ['Subject headings', $q('SELECT COUNT(*) FROM Authority'), 'crud.php?e=authority'],
    ['Items on loan', $q('SELECT COUNT(*) FROM Borrower WHERE Date_returned IS NULL'), 'loans.php'],
    ['Overdue items', $q("SELECT COUNT(*) FROM Borrower WHERE Date_returned IS NULL AND Status <> 'Lost' AND Due_date < CURDATE()"), 'loans.php?f=overdue'],
];
$byLoc = db()->query("SELECT COALESCE(Location,'(none)') AS k, COUNT(*) AS n FROM AcList GROUP BY Location ORDER BY n DESC LIMIT 8")->fetchAll();
$max = max(1, ...array_column($byLoc, 'n'));
include __DIR__ . '/includes/header.php';
?>
<section class="banner"><div><h1>La Consolacion College Tanauan</h1><h2 class="banner-sub">St. Augustine Learning Resource Center</h2><p>Library management</p></div></section>
<div class="cards">
  <?php foreach ($stats as [$label, $n, $href]): ?>
    <a class="card stat" href="<?= e($href) ?>"><span class="num"><?= number_format($n) ?></span><span><?= e($label) ?></span></a>
  <?php endforeach; ?>
</div>
<section class="card">
  <h2>Books by location</h2>
  <?php foreach ($byLoc as $r): ?>
    <div class="bar-row"><span class="bar-label"><?= e($r['k']) ?></span>
      <span class="bar"><i style="width:<?= round($r['n'] / $max * 100) ?>%"></i></span>
      <span class="bar-n"><?= number_format($r['n']) ?></span></div>
  <?php endforeach; ?>
</section>
<section class="card">
  <h2>What is in this database?</h2>
  <p>The original system stored its data in an Access file (<code>Temp.mdb</code>). Everything was converted to MySQL.
     The tables on the left menu have add / edit / delete screens. Every other table (mostly scratch tables the old
     program used while printing reports) can be viewed under <a href="browse.php">All tables</a>.</p>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
