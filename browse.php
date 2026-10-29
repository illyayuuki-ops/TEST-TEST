<?php
// Read-only viewer for ALL 60 converted tables (including the old temp/scratch tables).
require __DIR__ . '/includes/bootstrap.php';
$tables = all_tables(); $names = array_column($tables, 't');
$t = $_GET['t'] ?? '';
$pageTitle = 'All tables';
include __DIR__ . '/includes/header.php';

if ($t === '' || !in_array($t, $names, true)) { ?>
  <h1>All tables <small class="muted">(<?= count($tables) ?>)</small></h1>
  <p class="muted">These are read-only. Tables with 0 rows are empty in the original file.</p>
  <input class="filter no-print" type="search" data-filter="#tbllist li" placeholder="Filter table names…">
  <ul class="tbl-list" id="tbllist">
    <?php foreach ($tables as $r): ?>
      <li><a href="browse.php?t=<?= e(urlencode($r['t'])) ?>"><?= e($r['t']) ?></a><span><?= number_format($r['n']) ?> rows</span></li>
    <?php endforeach; ?></ul>
<?php } else {
    $per = $CONFIG['per_page']; $page = max(1, (int)($_GET['p'] ?? 1));
    $total = (int) db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $rows = db()->query("SELECT * FROM `$t` LIMIT $per OFFSET " . (($page - 1) * $per))->fetchAll();
    $cols = $rows ? array_keys($rows[0]) : array_column(db()->query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field'); ?>
  <p><a href="browse.php">‹ All tables</a></p>
  <h1><?= e($t) ?> <small class="muted"><?= number_format($total) ?> rows</small></h1>
  <div class="table-wrap"><table class="data" data-sortable>
    <thead><tr><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr><?php foreach ($cols as $c): ?><td><?= e(shorten($r[$c])) ?></td><?php endforeach; ?></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="<?= count($cols) ?>" class="muted">This table is empty.</td></tr><?php endif; ?></tbody></table></div>
  <?php if ($pages > 1): ?><nav class="pager">
    <?php if ($page > 1): ?><a href="browse.php?t=<?= e(urlencode($t)) ?>&p=<?= $page - 1 ?>">‹ Prev</a><?php endif; ?>
    <span>Page <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a href="browse.php?t=<?= e(urlencode($t)) ?>&p=<?= $page + 1 ?>">Next ›</a><?php endif; ?></nav><?php endif; ?>
<?php }
include __DIR__ . '/includes/footer.php';
