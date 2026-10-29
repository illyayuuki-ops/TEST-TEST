<?php
// Renewal / due-date slip for one loan (print and hand it to the borrower).
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db(); $st = $pdo->prepare('SELECT * FROM Borrower WHERE ID = ?'); $st->execute([(int)($_GET['loan'] ?? 0)]); $l = $st->fetch();
if (!$l) { http_response_code(404); exit('Loan not found.'); }
$c = client_by_id((string)$l['ID_Number']);
$pageTitle = 'Renewal slip';
include __DIR__ . '/includes/header.php'; ?>
<section class="card" style="max-width:380px;margin:12px auto;padding:18px;line-height:1.6;font-size:.95rem">
  <div style="text-align:center"><b>La Consolacion College Tanauan</b><br><small>St. Augustine Learning Resource Center</small><h2 style="margin:8px 0">DUE DATE SLIP</h2></div>
  <div><b><?= e($c['Name'] ?? $l['ID_Number']) ?></b><br><small><?= e($l['ID_Number']) ?></small></div>
  <hr><div><?= e(shorten($l['Title'], 90)) ?><br><small>Accession <?= e($l['Accession_number']) ?></small></div>
  <hr><div>Borrowed: <?= e(fdate($l['Date_Borrowed'])) ?><br><b>Return on or before: <?= e(fdate($l['Due_date'])) ?></b></div>
  <p style="margin:8px 0 0"><small>Printed <?= e(date('M j, Y g:i A')) ?> · <?= e($_SESSION['name'] ?? '') ?></small></p>
  <p class="no-print" style="text-align:center;margin-top:14px"><button type="button" class="btn primary" data-print>Print</button></p></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
