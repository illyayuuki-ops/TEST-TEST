<?php
// Referral slip: a letter that refers a borrower to another library for research.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pageTitle = 'Referral slip';
[, $signatory] = ce_notice_text();
$idnum = trim($_GET['idnum'] ?? ''); $q = trim($_GET['q'] ?? '');
$client = $idnum !== '' ? client_by_id($idnum) : null; $found = [];
if (!$client && $q !== '') { $found = ce_find_clients($q); if (count($found) === 1) $client = client_by_id($found[0]['IDnum']); }
$to = trim($_GET['to'] ?? ''); $purpose = trim($_GET['purpose'] ?? ''); $date = valid_date($_GET['date'] ?? '', date('Y-m-d')); $valid = valid_date($_GET['valid'] ?? '', '');
$purposes = []; $pf = __DIR__ . '/data/lookups/Purpose.txt'; if (is_file($pf)) $purposes = array_values(array_filter(array_map('trim', preg_split('/[;\r\n]+/', (string)file_get_contents($pf)))));
include __DIR__ . '/includes/header.php'; ?>
<h1>Referral slip</h1>
<form class="card form no-print" method="get">
  <label><span>Borrower (ID number or name)</span><input name="q" value="<?= e($q ?: $idnum) ?>" required></label>
  <label><span>Refer to (library or school)</span><input name="to" value="<?= e($to) ?>" maxlength="120" placeholder="e.g. Batangas State University Library"></label>
  <label><span>Purpose</span><input name="purpose" value="<?= e($purpose) ?>" maxlength="150" list="purp" placeholder="e.g. thesis research"><datalist id="purp"><?php foreach ($purposes as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist></label>
  <label><span>Date</span><input type="date" name="date" value="<?= e($date) ?>"></label><label><span>Valid until (optional)</span><input type="date" name="valid" value="<?= e($valid) ?>"></label>
  <div class="actions"><button class="btn primary">Prepare slip</button></div></form>
<?php if (!$client && $found): ?><div class="table-wrap no-print"><table class="data"><tbody><?php foreach ($found as $f): ?><tr><td><a href="referral.php?<?= e(http_build_query(['idnum' => $f['IDnum'], 'to' => $to, 'purpose' => $purpose, 'date' => $date, 'valid' => $valid])) ?>"><?= e($f['Name']) ?></a></td><td><?= e($f['IDnum']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if ($client): ?>
<section class="card" style="max-width:680px;margin:12px auto;padding:28px;line-height:1.7">
  <div style="text-align:center"><b style="font-size:1.15rem">La Consolacion College Tanauan</b><br>St. Augustine Learning Resource Center<h2 style="margin:14px 0 4px;letter-spacing:.08em">REFERRAL</h2></div>
  <p><?= e(fdate($date)) ?></p>
  <p><b>The Librarian</b><br><?= e($to !== '' ? $to : '________________________________') ?></p>
  <p>Dear Sir or Madam,</p>
  <p>This is to introduce <b><?= e($client['Name']) ?></b>, a bona fide <?= e($client['User_class'] ?: 'member') ?> of La Consolacion College Tanauan (ID number <?= e($client['IDnum']) ?><?= $client['Degree_Course'] ? ', ' . e($client['Degree_Course']) . ' ' . e($client['Year_Level']) : '' ?>).</p>
  <p>He or she is referred to your library for <?= e($purpose !== '' ? $purpose : 'research') ?>. We would appreciate any assistance you can give.<?= $valid !== '' ? ' This referral is valid until ' . e(fdate($valid)) . '.' : '' ?></p>
  <p>Thank you.</p>
  <div style="margin-top:40px"><div style="display:inline-block;min-width:240px;border-top:1px solid #000;text-align:center;padding-top:4px"><?= e($signatory !== '' ? $signatory : ($_SESSION['name'] ?? '')) ?><br><small>Librarian</small></div></div>
  <p class="no-print" style="text-align:center;margin-top:18px"><button type="button" class="btn primary" data-print>Print</button></p></section>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
