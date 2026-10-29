<?php
// Circulation desk: find a borrower, then borrow / return / renew / mark lost.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db();
$idnum = trim($_GET['idnum'] ?? $_POST['idnum'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $client = client_by_id($idnum);
    if (!$client) { flash('Not done: borrower not found.'); redirect('circulation.php'); }
    switch ($_POST['do'] ?? '') {
        case 'checkout': [$ok, $msg] = circ_checkout($client, trim($_POST['accession'] ?? '')); break;
        case 'return':   [$ok, $msg] = circ_return((int)($_POST['loan'] ?? 0), $idnum, !empty($_POST['fine_paid'])); break;
        case 'renew':    [$ok, $msg] = circ_renew((int)($_POST['loan'] ?? 0), $idnum); break;
        case 'lost':     [$ok, $msg] = circ_lost((int)($_POST['loan'] ?? 0), $idnum); break;
        case 'photo':    [$ok, $msg] = ce_photo_save($idnum, $_FILES['photo'] ?? []); break;
        case 'photo_remove': ce_photo_remove($idnum); [$ok, $msg] = [true, 'Photo removed.']; break;
        default:         [$ok, $msg] = [false, 'Unknown action.'];
    }
    flash(($ok ? '' : 'Not done: ') . $msg);
    redirect('circulation.php?idnum=' . urlencode($idnum));
}

$pageTitle = 'Circulation';
$client = client_by_id($idnum);
$matches = [];
if ($idnum !== '' && !$client) {
    $st = $pdo->prepare('SELECT IDnum, Name, User_class FROM Client WHERE Name LIKE ? OR IDnum LIKE ? ORDER BY Name LIMIT 20');
    $st->execute(["%$idnum%", "%$idnum%"]); $matches = $st->fetchAll();
}
include __DIR__ . '/includes/header.php'; ?>
<h1>Circulation</h1>
<form class="toolbar" method="get">
  <input type="search" name="idnum" value="<?= e($idnum) ?>" placeholder="Borrower ID number (or part of the name)" autofocus>
  <button class="btn primary">Find borrower</button>
</form>

<?php if ($idnum !== '' && !$client): ?>
  <?php if ($matches): ?>
    <p class="muted">No exact ID match. Did you mean:</p>
    <div class="table-wrap"><table class="data"><thead><tr><th>ID number</th><th>Name</th><th>Class</th></tr></thead><tbody>
    <?php foreach ($matches as $m): ?><tr><td><a href="circulation.php?idnum=<?= e(urlencode($m['IDnum'])) ?>"><?= e($m['IDnum']) ?></a></td><td><?= e($m['Name']) ?></td><td><?= e($m['User_class']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?>
    <p>No borrower found. <a href="crud.php?e=clients&a=add">Add a new borrower</a>.</p>
  <?php endif; ?>
<?php elseif ($client):
    $st = $pdo->prepare("SELECT * FROM Borrower WHERE ID_Number = ? AND Date_returned IS NULL ORDER BY Due_date"); $st->execute([$idnum]); $loans = $st->fetchAll();
    $st = $pdo->prepare("SELECT * FROM Borrower WHERE ID_Number = ? AND Date_returned IS NOT NULL ORDER BY Date_returned DESC LIMIT 15"); $st->execute([$idnum]); $hist = $st->fetchAll();
    [$n, $lateCount] = loan_counts($idnum); ?>
  <?php $photo = ce_photo_url($idnum); $owing = ce_unpaid_total($idnum);
        $nh = 0; if (ce_has_hold_kind()) { $hq = $pdo->prepare("SELECT COUNT(*) FROM Reservation WHERE IDnumber = ? AND (Claimed IS NULL OR Claimed <> 'Yes') AND DateExpired >= ?"); $hq->execute([$idnum, date('Y-m-d')]); $nh = (int)$hq->fetchColumn(); } ?>
  <div class="card client-card">
    <div class="photo-box">
      <?php if ($photo): ?><img class="photo" src="<?= e($photo) ?>" alt="Photo of <?= e($client['Name']) ?>"><?php else: ?><div class="photo none" aria-hidden="true"><?= e(strtoupper(substr($client['Name'], 0, 1))) ?></div><?php endif; ?>
      <details class="no-print photo-edit"><summary class="link"><?= $photo ? 'Change photo' : 'Add photo' ?></summary>
        <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="idnum" value="<?= e($idnum) ?>"><input type="hidden" name="do" value="photo">
          <input type="file" name="photo" accept="image/jpeg,image/png" required> <button class="btn">Save</button></form>
        <?php if ($photo): ?><form method="post" data-confirm="Remove this photo?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="idnum" value="<?= e($idnum) ?>"><button class="link danger" name="do" value="photo_remove">Remove photo</button></form><?php endif; ?></details>
    </div>
    <div>
      <h2><?= e($client['Name']) ?> <small class="muted">· ID <?= e($client['IDnum']) ?></small></h2>
      <p class="muted" style="margin:0"><?= e(implode(' · ', array_filter([$client['Grade_level'] ?? null, $client['User_class'], $client['Degree_Course'], $client['Year_Level'], $client['Section'] ?? null]))) ?: 'No class or course on file' ?>
        · <?= $n ?> of <?= (int)circ_cfg()['max_loans'] ?> loans<?= $lateCount ? ' · <span class="late">' . $lateCount . ' overdue</span>' : '' ?></p>
      <?php if ($owing > 0): ?><p style="margin:6px 0 0"><strong class="danger">Owes <?= e(money($owing)) ?></strong> <a class="no-print" href="balance.php?idnum=<?= e(urlencode($idnum)) ?>">Pay or settle</a></p><?php endif; ?>
      <?php if ($nh): ?><p style="margin:6px 0 0"><?= $nh ?> item<?= $nh === 1 ? '' : 's' ?> waiting for this borrower <a class="no-print" href="holds.php">View holds</a></p><?php endif; ?>
      <p class="no-print" style="margin:8px 0 0"><a href="history.php?idnum=<?= e(urlencode($idnum)) ?>">History</a> · <a href="balance.php?t=estimate&idnum=<?= e(urlencode($idnum)) ?>">Estimate fine</a> · <a href="holds.php?idnum=<?= e(urlencode($idnum)) ?>">Hold an item</a></p>
    </div>
  </div>

  <form method="post" class="toolbar">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="checkout">
    <input type="hidden" name="idnum" value="<?= e($idnum) ?>">
    <input type="text" name="accession" placeholder="Accession number to borrow (e.g. COL-000001)" required>
    <button class="btn primary">Borrow</button>
  </form>

  <h2>On loan</h2>
  <div class="table-wrap"><table class="data"><thead><tr><th>Accession</th><th>Title</th><th>Borrowed</th><th>Due</th><th>Status</th><th>Fine now</th><th></th></tr></thead><tbody>
  <?php foreach ($loans as $l):
      $lost = $l['Status'] === 'Lost'; $late = $lost ? 0 : late_days($l['Due_date']);
      $fine = $late * fine_rate($client['User_class'], is_overnight($l['Library'])); ?>
    <tr>
      <td><?= e($l['Accession_number']) ?></td><td><?= e($l['Title']) ?></td><td><?= e(fdate($l['Date_Borrowed'])) ?></td>
      <td class="<?= $late ? 'late' : '' ?>"><?= e(fdate($l['Due_date'])) ?></td>
      <td><?= e($lost ? 'Lost' : ($late ? "Overdue · $late day(s)" : $l['Status'])) ?></td>
      <td><?= $fine > 0 ? e(money($fine)) : '' ?></td>
      <td class="row-actions">
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="idnum" value="<?= e($idnum) ?>"><input type="hidden" name="loan" value="<?= (int)$l['ID'] ?>">
          <?php if ($fine > 0): ?><label class="inline"><input type="checkbox" name="fine_paid" value="1" checked> fine paid</label><?php endif; ?>
          <button class="link" name="do" value="return">Return</button>
          <?php if (!$lost): ?><button class="link" name="do" value="renew">Renew</button><?php endif; ?>
        </form>
        <?php if (!$lost): ?><a href="renewal_slip.php?loan=<?= (int)$l['ID'] ?>" target="_blank" rel="noopener">Slip</a><?php endif; ?>
        <?php if (!$lost): ?><form method="post" data-confirm="Mark this item as lost?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="idnum" value="<?= e($idnum) ?>"><input type="hidden" name="loan" value="<?= (int)$l['ID'] ?>"><button class="link danger" name="do" value="lost">Lost</button></form><?php endif; ?>
      </td></tr>
  <?php endforeach; if (!$loans): ?><tr><td colspan="7" class="muted">Nothing on loan.</td></tr><?php endif; ?>
  </tbody></table></div>

  <h2 style="margin-top:24px">Recent history</h2>
  <div class="table-wrap"><table class="data"><thead><tr><th>Accession</th><th>Title</th><th>Borrowed</th><th>Returned</th><th>Days late</th></tr></thead><tbody>
  <?php foreach ($hist as $h): ?><tr><td><?= e($h['Accession_number']) ?></td><td><?= e($h['Title']) ?></td><td><?= e(fdate($h['Date_Borrowed'])) ?></td><td><?= e(fdate($h['Date_returned'])) ?></td><td><?= (int)$h['Overdue'] ?: '' ?></td></tr>
  <?php endforeach; if (!$hist): ?><tr><td colspan="5" class="muted">No returned items yet.</td></tr><?php endif; ?></tbody></table></div>
<?php else: ?>
  <p class="muted">Type a borrower's ID number (or part of their name) to borrow, return or renew items.</p>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
