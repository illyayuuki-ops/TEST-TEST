<?php
// Book reservations: reserve an item for a borrower, mark it claimed, or cancel.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'add') {
        $client = client_by_id(trim($_POST['idnum'] ?? '')); $item = find_item(trim($_POST['accession'] ?? ''));
        if (!$client) flash('Not done: borrower not found.');
        elseif (!$item) flash('Not done: no item with that accession number.');
        elseif ($ex = active_reservation($item['Accession'])) flash('Not done: already reserved for ' . $ex['Name'] . ' until ' . fdate($ex['DateExpired']) . '.');
        else {
            $days = (int)circ_cfg()['reservation_days'];
            $pdo->prepare("INSERT INTO Reservation (IDnumber, Name, Accession, Title, Call_number, Type, DateReserved, DateExpired, Claimed)
                           VALUES (?, ?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL $days DAY), 'No')")
                ->execute([$client['IDnum'], $client['Name'], $item['Accession'], $item['Title'], $item['Call_Number'], substr($item['Kind'], 0, 10)]);
            flash("Reserved \"{$item['Title']}\" for {$client['Name']} (held $days days).");
        }
    } elseif ($do === 'claim') {
        $pdo->prepare("UPDATE Reservation SET Claimed = 'Yes' WHERE MFN = ?")->execute([(int)$_POST['id']]); flash('Marked as claimed.');
    } elseif ($do === 'cancel') {
        $pdo->prepare('DELETE FROM Reservation WHERE MFN = ?')->execute([(int)$_POST['id']]); flash('Reservation cancelled.');
    }
    redirect('reservations.php');
}

$pageTitle = 'Reservations';
$show = ($_GET['f'] ?? 'active') === 'all' ? 'all' : 'active';
$sql = 'SELECT * FROM Reservation ' . ($show === 'active' ? "WHERE (Claimed IS NULL OR Claimed <> 'Yes') AND DateExpired >= CURDATE() " : '') . 'ORDER BY DateReserved DESC, MFN DESC LIMIT 200';
$rows = $pdo->query($sql)->fetchAll();
include __DIR__ . '/includes/header.php'; ?>
<h1>Reservations</h1>
<form method="post" class="toolbar no-print"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="add">
  <input type="text" name="idnum" placeholder="Borrower ID number" required><input type="text" name="accession" placeholder="Accession number" required>
  <button class="btn primary">Reserve</button></form>
<p class="tabs no-print"><a class="<?= $show === 'active' ? 'on' : '' ?>" href="reservations.php">Active</a><a class="<?= $show === 'all' ? 'on' : '' ?>" href="reservations.php?f=all">All</a></p>
<div class="table-wrap"><table class="data"><thead><tr><th>Borrower</th><th>Kind</th><th>Accession</th><th>Title</th><th>Reserved</th><th>Expires</th><th>Claimed</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td><?= e($r['Name']) ?> <span class="muted">(<?= e($r['IDnumber']) ?>)</span></td><td><?= e(($r['Kind'] ?? 'Reserve') === 'Hold' ? 'Hold' : 'Reserve') ?></td><td><?= e($r['Accession']) ?></td><td><?= e($r['Title']) ?></td>
  <td><?= e(fdate($r['DateReserved'])) ?></td><td><?= e(fdate($r['DateExpired'])) ?></td><td><?= e($r['Claimed']) ?></td>
  <td class="no-print row-actions">
    <?php if (($r['Claimed'] ?? '') !== 'Yes'): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$r['MFN'] ?>"><button class="link" name="do" value="claim">Claimed</button></form><?php endif; ?>
    <form method="post" data-confirm="Cancel this reservation?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$r['MFN'] ?>"><button class="link danger" name="do" value="cancel">Cancel</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8" class="muted">No reservations.</td></tr><?php endif; ?></tbody></table></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
