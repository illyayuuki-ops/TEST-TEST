<?php
// Print catalog cards, call number (spine) labels, book labels with barcode, shelf cards, and record lists for a chosen range of records.
require __DIR__ . '/includes/bootstrap.php';
if (!can_edit_records()) deny();
$pdo = db(); $libs = libraries();
$pageTitle = 'Cards & labels';

// Code 39 barcode as inline SVG (scans with any ordinary barcode reader)
function code39_svg(string $text, int $h = 34): string {
    static $t = ['0'=>'nnnwwnwnn','1'=>'wnnwnnnnw','2'=>'nnwwnnnnw','3'=>'wnwwnnnnn','4'=>'nnnwwnnnw','5'=>'wnnwwnnnn','6'=>'nnwwwnnnn','7'=>'nnnwnnwnw','8'=>'wnnwnnwnn','9'=>'nnwwnnwnn',
        'A'=>'wnnnnwnnw','B'=>'nnwnnwnnw','C'=>'wnwnnwnnn','D'=>'nnnnwwnnw','E'=>'wnnnwwnnn','F'=>'nnwnwwnnn','G'=>'nnnnnwwnw','H'=>'wnnnnwwnn','I'=>'nnwnnwwnn','J'=>'nnnnwwwnn',
        'K'=>'wnnnnnnww','L'=>'nnwnnnnww','M'=>'wnwnnnnwn','N'=>'nnnnwnnww','O'=>'wnnnwnnwn','P'=>'nnwnwnnwn','Q'=>'nnnnnnwww','R'=>'wnnnnnwwn','S'=>'nnwnnnwwn','T'=>'nnnnwnwwn',
        'U'=>'wwnnnnnnw','V'=>'nwwnnnnnw','W'=>'wwwnnnnnn','X'=>'nwnnwnnnw','Y'=>'wwnnwnnnn','Z'=>'nwwnwnnnn','-'=>'nwnnnnwnw','.'=>'wwnnnnwnn',' '=>'nwwnnnwnn','*'=>'nwnnwnwnn',
        '$'=>'nwnwnwnnn','/'=>'nwnwnnnwn','+'=>'nwnnnwnwn','%'=>'nnnwnwnwn'];
    $text = strtoupper(preg_replace('/[^A-Za-z0-9 .$\/+%-]/', '', $text)); if ($text === '') return '';
    $x = 0.0; $bars = '';
    foreach (str_split('*' . $text . '*') as $ci => $ch) {
        foreach (str_split($t[$ch]) as $i => $w) {
            $wd = $w === 'w' ? 2.5 : 1.0;
            if ($i % 2 === 0) $bars .= '<rect x="' . round($x, 2) . '" y="0" width="' . $wd . '" height="' . $h . '"/>';
            $x += $wd;
        }
        $x += 1.0;
    }
    return '<svg class="bc" viewBox="0 0 ' . round($x, 1) . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="Barcode ' . e($text) . '" fill="#000">' . $bars . '</svg>';
}

$modes = ['card' => 'Catalog cards (3 x 5)', 'spine' => 'Call number labels', 'book' => 'Book labels with barcode', 'shelf' => 'Shelf cards', 'list' => 'Record list (range printing)'];
$mode = $_GET['mode'] ?? 'card'; if (!isset($modes[$mode])) $mode = 'card';
$kind = ($_GET['kind'] ?? 'Books') === 'Non-book' ? 'Non-book' : 'Books';
$lib = trim($_GET['lib'] ?? ''); $af = trim($_GET['af'] ?? ''); $at = trim($_GET['at'] ?? ''); $call = trim($_GET['call'] ?? '');
$yf = trim($_GET['yf'] ?? ''); $yt = trim($_GET['yt'] ?? ''); $q = trim($_GET['q'] ?? '');
$go = isset($_GET['go']);
$rows = [];
if ($go) {
    $tbl = $kind === 'Books' ? 'AcList' : 'AccSM'; $w = ['1=1']; $args = [];
    if ($lib !== '' && in_array($lib, $libs, true)) { $w[] = 'Location LIKE :lib'; $args['lib'] = "$lib%"; }
    if ($af !== '') { $w[] = 'Accession >= :af'; $args['af'] = $af; }
    if ($at !== '') { $w[] = 'Accession <= :at'; $args['at'] = $at; }
    if ($call !== '') { $w[] = 'Call_Number LIKE :call'; $args['call'] = "$call%"; }
    if ($yf !== '') { $w[] = 'Ye_ar >= :yf'; $args['yf'] = $yf; }
    if ($yt !== '') { $w[] = 'Ye_ar <= :yt'; $args['yt'] = $yt; }
    if ($q !== '') { $w[] = '(Title LIKE :q OR Author LIKE :q2)'; $args['q'] = $args['q2'] = "%$q%"; }
    $st = $pdo->prepare("SELECT * FROM `$tbl` WHERE " . implode(' AND ', $w) . ' ORDER BY Call_Number, Accession LIMIT 301'); $st->execute($args); $rows = $st->fetchAll();
}
$tooMany = count($rows) > 300; if ($tooMany) array_pop($rows);
$schoolName = (string)(circ_cfg_safe()['library_name'] ?? 'La Consolacion College Tanauan');
function circ_cfg_safe(): array { return $GLOBALS['CONFIG']['circulation'] ?? []; }
include __DIR__ . '/includes/header.php'; ?>
<style>
 .sheet{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start}
 .pc{border:1px dashed #8a93a6;background:#fff;color:#000;page-break-inside:avoid;break-inside:avoid;padding:8px 10px;overflow:hidden;font-family:Georgia,serif}
 .pc.card{width:5in;height:3in;font-size:12.5pt;line-height:1.3;position:relative}.pc.card .cn{float:left;width:1.2in;font-weight:700;font-family:Arial,sans-serif;font-size:11pt;line-height:1.35}
 .pc.card .body{margin-left:1.3in}.pc.card .acc{position:absolute;right:10px;bottom:6px;font-size:9pt;font-family:Arial,sans-serif}
 .pc.spine{width:1.5in;height:1.15in;text-align:center;font-family:Arial,sans-serif;font-weight:700;font-size:12pt;line-height:1.2;display:flex;flex-direction:column;justify-content:center}
 .pc.book{width:2.6in;height:1.45in;font-family:Arial,sans-serif;font-size:9.5pt;line-height:1.25;text-align:center}.pc.book b{display:block;font-size:10pt}.pc.book .cn{font-size:14pt;font-weight:700;margin:3px 0}
 .pc.shelf{width:5in;height:3in;font-size:12pt;line-height:1.35;position:relative}.pc.shelf h4{margin:0 0 6px;font-family:Arial,sans-serif;font-size:11pt;letter-spacing:.04em;text-transform:uppercase;border-bottom:1px solid #000;padding-bottom:3px}
 .bc{display:block;width:100%;height:34px;margin:4px 0 2px}
 @media print{.sheet{gap:0}.pc{border-color:#bbb}@page{margin:.4in}}
</style>
<h1>Cards &amp; labels</h1>
<p class="muted no-print">Choose what to print and which records. Then press Print. Cards and labels are laid out at real size on the page.</p>
<form class="card form no-print" method="get"><input type="hidden" name="go" value="1">
  <label><span>Print</span><select name="mode"><?php foreach ($modes as $k => $l): ?><option value="<?= e($k) ?>" <?= $mode === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <label><span>Records</span><select name="kind"><option <?= $kind === 'Books' ? 'selected' : '' ?>>Books</option><option <?= $kind === 'Non-book' ? 'selected' : '' ?>>Non-book</option></select></label>
  <label><span>Library / location starts with</span><select name="lib"><option value="">All</option><?php foreach ($libs as $l): ?><option <?= $l === $lib ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <label><span>Accession from</span><input name="af" value="<?= e($af) ?>" placeholder="e.g. COL-000001"></label>
  <label><span>Accession to</span><input name="at" value="<?= e($at) ?>" placeholder="e.g. COL-000050"></label>
  <label><span>Call number starts with</span><input name="call" value="<?= e($call) ?>" placeholder="e.g. CIR 500"></label>
  <label><span>Year from</span><input name="yf" value="<?= e($yf) ?>" maxlength="4"></label><label><span>Year to</span><input name="yt" value="<?= e($yt) ?>" maxlength="4"></label>
  <label><span>Title or author contains</span><input name="q" value="<?= e($q) ?>"></label>
  <div class="actions"><button class="btn primary">Show</button> <?php if ($rows): ?><button type="button" class="btn" data-print>Print</button><?php endif; ?></div>
</form>
<?php if ($go): ?>
<p class="muted no-print"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?><?= $tooMany ? ' (the first 300 are shown. Narrow the range to print the rest.)' : '' ?></p>
<?php if ($mode === 'list'): ?>
 <h2 style="margin:6px 0"><?= e($schoolName) ?> · <?= e($kind) ?> list</h2>
 <div class="table-wrap"><table class="data"><thead><tr><th>Accession</th><th>Call number</th><th>Title</th><th>Author</th><th>Year</th><th>Location</th></tr></thead><tbody>
 <?php foreach ($rows as $r): ?><tr><td><?= e($r['Accession']) ?></td><td><?= e($r['Call_Number']) ?></td><td><?= e(shorten($r['Title'], 90)) ?></td><td><?= e($r['Author']) ?></td><td><?= e($r['Ye_ar']) ?></td><td><?= e($r['Location']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><div class="sheet">
 <?php foreach ($rows as $r): $cn = trim((string)$r['Call_Number']); $title = (string)$r['Title']; ?>
  <?php if ($mode === 'card'): ?>
   <div class="pc card"><div class="cn"><?= nl2br(e(implode("\n", preg_split('/\s+/', $cn, -1, PREG_SPLIT_NO_EMPTY)))) ?></div>
    <div class="body"><b><?= e($r['Author']) ?></b><br><?= e(shorten($title, 160)) ?><br><?= e(trim(($r['Publisher'] ?? '') . ($r['Ye_ar'] ? ', ' . $r['Ye_ar'] : ''), ', ')) ?><?= !empty($r['ISBN']) ? '<br>ISBN ' . e($r['ISBN']) : '' ?></div><div class="acc"><?= e($r['Accession']) ?></div></div>
  <?php elseif ($mode === 'spine'): ?>
   <div class="pc spine"><?php foreach (preg_split('/\s+/', $cn, -1, PREG_SPLIT_NO_EMPTY) as $part): ?><div><?= e($part) ?></div><?php endforeach; if ($cn === '') echo '<div>' . e($r['Accession']) . '</div>'; ?></div>
  <?php elseif ($mode === 'book'): ?>
   <div class="pc book"><b><?= e($schoolName) ?></b><div class="cn"><?= e($cn) ?></div><?= code39_svg((string)$r['Accession']) ?><span><?= e($r['Accession']) ?></span></div>
  <?php else: ?>
   <div class="pc shelf"><h4>Shelf list · <?= e($r['Location']) ?></h4><b><?= e($cn) ?></b><br><?= e($r['Author']) ?><br><?= e(shorten($title, 160)) ?><br><?= e($r['Ye_ar']) ?><div class="acc" style="position:absolute;right:10px;bottom:6px;font:9pt Arial"><?= e($r['Accession']) ?></div></div>
  <?php endif; endforeach; ?></div>
<?php endif; endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
