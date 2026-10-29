<?php
// MARC export: converts catalog records to MARC 21 (ISO 2709 .mrc file, MARCXML, or readable text) and checks them first.
require __DIR__ . '/includes/bootstrap.php';
if (!can_edit_records()) deny();
$pdo = db(); $libs = libraries();
$kind = ($_GET['kind'] ?? 'Books') === 'Non-book' ? 'Non-book' : 'Books';
$lib = trim($_GET['lib'] ?? ''); $af = trim($_GET['af'] ?? ''); $at = trim($_GET['at'] ?? ''); $call = trim($_GET['call'] ?? '');
$dl = $_GET['dl'] ?? ''; $go = isset($_GET['go']) || $dl !== '';
const MARC_MAX = 5000;

function marc_isbn_ok(string $s): bool {
    $s = strtoupper(preg_replace('/[^0-9Xx]/', '', $s));
    if (strlen($s) === 10) { $sum = 0; for ($i = 0; $i < 10; $i++) { $c = $s[$i]; $v = $c === 'X' ? 10 : (ctype_digit($c) ? (int)$c : -1); if ($v < 0 || ($c === 'X' && $i < 9)) return false; $sum += $v * (10 - $i); } return $sum % 11 === 0; }
    if (strlen($s) === 13 && ctype_digit($s)) { $sum = 0; for ($i = 0; $i < 13; $i++) $sum += (int)$s[$i] * ($i % 2 ? 3 : 1); return $sum % 10 === 0; }
    return false;
}
// One catalog row -> MARC fields. [tag, indicators-or-null, data-or-subfields]
function marc_fields(array $r, string $kind): array {
    $t = fn($v) => trim(preg_replace('/\s+/', ' ', (string)($v ?? '')));
    $f = [['001', null, $t($r['Accession'])], ['005', null, date('YmdHis') . '.0']];
    $f[] = ['008', null, date('ymd') . 's' . (preg_match('/^\d{4}$/', $t($r['Ye_ar'])) ? $t($r['Ye_ar']) : '    ') . '    ph |||||||||||| ||eng d'];
    if ($t($r['ISBN']) !== '') $f[] = ['020', '  ', [['a', $t($r['ISBN'])]]];
    if ($t($r['Call_Number']) !== '') $f[] = ['090', '  ', [['a', $t($r['Call_Number'])]]];
    if ($t($r['Author']) !== '') $f[] = ['100', '1 ', [['a', $t($r['Author'])]]];
    $f[] = ['245', ($t($r['Author']) !== '' ? '1' : '0') . '0', [['a', $t($r['Title'])]]];
    $pub = []; if ($t($r['Publisher']) !== '') $pub[] = ['b', $t($r['Publisher'])]; if ($t($r['Ye_ar']) !== '') $pub[] = ['c', $t($r['Ye_ar'])];
    if ($pub) $f[] = ['260', '  ', $pub];
    if ($kind === 'Non-book' && $t($r['Type'] ?? '') !== '') $f[] = ['655', ' 7', [['a', $t($r['Type'])]]];
    $h = [['a', 'La Consolacion College Tanauan']]; if ($t($r['Location']) !== '') $h[] = ['b', $t($r['Location'])];
    if ($t($r['Call_Number']) !== '') $h[] = ['h', $t($r['Call_Number'])]; $h[] = ['p', $t($r['Accession'])];
    $f[] = ['852', '  ', $h];
    return $f;
}
function marc_iso2709(array $fields): string {
    $FT = "\x1e"; $US = "\x1f"; $dir = ''; $data = ''; 
    foreach ($fields as [$tag, $ind, $d]) {
        if ($ind === null) $c = $d . $FT; else { $c = $ind; foreach ($d as [$code, $val]) $c .= $US . $code . $val; $c .= $FT; }
        $c = strlen($c) > 9999 ? substr($c, 0, 9998) . $FT : $c;
        $dir .= $tag . sprintf('%04d', strlen($c)) . sprintf('%05d', strlen($data)); $data .= $c;
    }
    $base = 24 + strlen($dir) + 1; $len = $base + strlen($data) + 1;
    return sprintf('%05d', $len) . 'nam a22' . sprintf('%05d', $base) . ' a 4500' . $dir . $FT . $data . "\x1d";
}
function marc_xml(array $fields): string {
    $x = "  <record>\n    <leader>00000nam a2200000 a 4500</leader>\n";
    foreach ($fields as [$tag, $ind, $d]) {
        if ($ind === null) { $x .= "    <controlfield tag=\"$tag\">" . htmlspecialchars($d, ENT_XML1) . "</controlfield>\n"; continue; }
        $x .= "    <datafield tag=\"$tag\" ind1=\"" . ($ind[0] === ' ' ? ' ' : $ind[0]) . "\" ind2=\"" . ($ind[1] === ' ' ? ' ' : $ind[1]) . "\">\n";
        foreach ($d as [$c, $v]) $x .= "      <subfield code=\"$c\">" . htmlspecialchars($v, ENT_XML1) . "</subfield>\n";
        $x .= "    </datafield>\n";
    }
    return $x . "  </record>\n";
}
function marc_text(array $fields): string {
    $o = '=LDR  00000nam a2200000 a 4500' . "\n";
    foreach ($fields as [$tag, $ind, $d]) { if ($ind === null) { $o .= "=$tag  $d\n"; continue; } $o .= "=$tag  " . str_replace(' ', '\\', $ind); foreach ($d as [$c, $v]) $o .= "\$$c$v"; $o .= "\n"; }
    return $o . "\n";
}
// Problems found in one record. 'error' records are left out of the export; 'warning' ones are exported.
function marc_check(array $r): array {
    $p = [];
    if (trim((string)$r['Accession']) === '') $p[] = ['error', 'No accession number'];
    if (trim((string)$r['Title']) === '') $p[] = ['error', 'No title'];
    if (trim((string)$r['Author']) === '') $p[] = ['warning', 'No author'];
    if (trim((string)$r['Call_Number']) === '') $p[] = ['warning', 'No call number'];
    $y = trim((string)$r['Ye_ar']); if ($y !== '' && !preg_match('/^\d{4}$/', $y)) $p[] = ['warning', "Year \"$y\" is not 4 digits"];
    $i = trim((string)($r['ISBN'] ?? '')); if ($i !== '' && !marc_isbn_ok($i)) $p[] = ['warning', "ISBN \"$i\" does not check out"];
    return $p;
}

$rows = [];
if ($go) {
    $tbl = $kind === 'Books' ? 'AcList' : 'AccSM'; $w = ['1=1']; $args = [];
    if ($lib !== '' && in_array($lib, $libs, true)) { $w[] = 'Location LIKE :lib'; $args['lib'] = "$lib%"; }
    if ($af !== '') { $w[] = 'Accession >= :af'; $args['af'] = $af; } if ($at !== '') { $w[] = 'Accession <= :at'; $args['at'] = $at; }
    if ($call !== '') { $w[] = 'Call_Number LIKE :call'; $args['call'] = "$call%"; }
    $st = $pdo->prepare("SELECT * FROM `$tbl` WHERE " . implode(' AND ', $w) . ' ORDER BY Accession LIMIT ' . (MARC_MAX + 1)); $st->execute($args); $rows = $st->fetchAll();
}
$tooMany = count($rows) > MARC_MAX; if ($tooMany) array_pop($rows);
$good = []; $issues = []; $nErr = 0; $nWarn = 0;
foreach ($rows as $r) {
    $p = marc_check($r); $hasErr = false;
    foreach ($p as [$lvl, $m]) { if ($lvl === 'error') { $hasErr = true; $nErr++; } else $nWarn++; }
    if ($p && count($issues) < 100) $issues[] = [$r, $p];
    if (!$hasErr) $good[] = $r;
}
if ($dl !== '' && in_array($dl, ['mrc', 'xml', 'txt'], true)) {
    $name = 'SALRC-' . strtolower(str_replace('-', '', $kind)) . '-' . date('Ymd');
    if ($dl === 'mrc') { header('Content-Type: application/marc'); header("Content-Disposition: attachment; filename=\"$name.mrc\""); foreach ($good as $r) echo marc_iso2709(marc_fields($r, $kind)); }
    elseif ($dl === 'xml') { header('Content-Type: application/xml; charset=utf-8'); header("Content-Disposition: attachment; filename=\"$name.xml\""); echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<collection xmlns=\"http://www.loc.gov/MARC21/slim\">\n"; foreach ($good as $r) echo marc_xml(marc_fields($r, $kind)); echo "</collection>\n"; }
    else { header('Content-Type: text/plain; charset=utf-8'); header("Content-Disposition: attachment; filename=\"$name.txt\""); foreach ($good as $r) echo marc_text(marc_fields($r, $kind)); }
    exit;
}
$pageTitle = 'MARC export';
include __DIR__ . '/includes/header.php'; ?>
<h1>MARC export</h1>
<p class="muted">Converts catalog records to MARC 21 so they can be shared or loaded into other library software. Records are checked first. Records without a title or accession number are left out.</p>
<form class="card form" method="get"><input type="hidden" name="go" value="1">
  <label><span>Records</span><select name="kind"><option <?= $kind === 'Books' ? 'selected' : '' ?>>Books</option><option <?= $kind === 'Non-book' ? 'selected' : '' ?>>Non-book</option></select></label>
  <label><span>Library / location starts with</span><select name="lib"><option value="">All</option><?php foreach ($libs as $l): ?><option <?= $l === $lib ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <label><span>Accession from</span><input name="af" value="<?= e($af) ?>"></label><label><span>Accession to</span><input name="at" value="<?= e($at) ?>"></label>
  <label><span>Call number starts with</span><input name="call" value="<?= e($call) ?>"></label>
  <div class="actions"><button class="btn primary">Check records</button></div></form>
<?php if ($go): $qs = http_build_query(['kind' => $kind, 'lib' => $lib, 'af' => $af, 'at' => $at, 'call' => $call]); ?>
<div class="cards">
 <div class="card stat"><span class="num"><?= number_format(count($rows)) ?></span><span>Records found<?= $tooMany ? ' (first ' . MARC_MAX . ')' : '' ?></span></div>
 <div class="card stat"><span class="num"><?= number_format(count($good)) ?></span><span>Ready to export</span></div>
 <div class="card stat"><span class="num"><?= number_format($nErr) ?></span><span>Errors (left out)</span></div>
 <div class="card stat"><span class="num"><?= number_format($nWarn) ?></span><span>Warnings (still exported)</span></div></div>
<?php if ($good): ?><p><a class="btn primary" href="marc.php?<?= e($qs) ?>&dl=mrc">Download .mrc (MARC 21)</a> <a class="btn" href="marc.php?<?= e($qs) ?>&dl=xml">MARCXML</a> <a class="btn" href="marc.php?<?= e($qs) ?>&dl=txt">Readable text</a></p><?php endif; ?>
<?php if ($issues): ?><section class="card"><h2 style="margin-top:0">Problems found<?= count($issues) >= 100 ? ' (first 100 records)' : '' ?></h2><div class="table-wrap"><table class="data"><thead><tr><th>Accession</th><th>Title</th><th>Problem</th></tr></thead><tbody>
 <?php foreach ($issues as [$r, $p]): ?><tr><td><?= e($r['Accession']) ?></td><td><?= e(shorten($r['Title'], 70)) ?></td><td><?php foreach ($p as [$lvl, $m]): ?><span class="pill <?= $lvl === 'error' ? 'inv-lost' : 'inv-out' ?>"><?= e($m) ?></span> <?php endforeach; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
