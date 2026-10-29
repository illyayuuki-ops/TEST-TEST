<?php
// Shows the old dropdown-list text files (rooms, languages, material types, letter wording ...)
require __DIR__ . '/includes/bootstrap.php';
$pageTitle = 'Lists';
$dir = __DIR__ . '/data/lookups/';
$files = glob($dir . '*.txt'); sort($files, SORT_NATURAL | SORT_FLAG_CASE);
include __DIR__ . '/includes/header.php'; ?>
<h1>Lists &amp; letter text</h1>
<p class="muted">These came from the small <code>.txt</code> files next to the old program. It used them to fill its dropdown boxes
  and printed letters. Items were separated by <code>;</code>.</p>
<div class="cards">
<?php foreach ($files as $f):
    $raw = trim(file_get_contents($f)); if ($raw === '') continue;
    $items = array_filter(array_map('trim', preg_split('/[;\r\n]+/', $raw)), 'strlen'); ?>
  <section class="card"><h2><?= e(basename($f, '.txt')) ?></h2>
    <?php if (count($items) > 1): ?><div class="chips"><?php foreach ($items as $i): ?><span><?= e($i) ?></span><?php endforeach; ?></div>
    <?php else: ?><p><?= e($raw) ?></p><?php endif; ?></section>
<?php endforeach; ?></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
