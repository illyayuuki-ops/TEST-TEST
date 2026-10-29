<?php
// Circulation > Online requests: hold requests students sent from the public catalog (OPAC). Approve = place the hold; reject = tell them why.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db();
if (!table_exists('online_request')) { $pageTitle = 'Online requests'; include __DIR__ . '/includes/header.php'; echo '<h1>Online requests</h1><div class="err">Import 13_opac_and_extras.sql in phpMyAdmin first, then reload this page.</div>'; include __DIR__ . '/includes/footer.php'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0); $do = $_POST['do'] ?? '';
    $st = $pdo->prepare("SELECT * FROM online_request WHERE ID = ? AND Status = 'Pending'"); $st->execute([$id]); $r = $st->fetch();
    $by = $_SESSION['name'] ?? ($_SESSION['user'] ?? '');
    if (!$r) flash('That request was already handled.');
    elseif ($do === 'approve') {
        $client = client_by_id($r['ID_Number']);
        if (!$client) flash('Not done: that borrower no longer exists.');
        else {
            [$ok, $m] = ce_place_hold($client, $r['Accession']);
            if ($ok) { $pdo->prepare("UPDATE online_request SET Status='Approved', Handled_by=?, Handled_at=NOW(), Note=NULL WHERE ID=?")->execute([$by, $id]); flash('Approved. ' . $m); }
            else flash('Not approved: ' . $m . ' You can reject the request instead.');
        }
    } elseif ($do === 'reject') {
        $note = substr(trim((string)($_POST['note'] ?? '')), 0, 150);
        $pdo->prepare("UPDATE online_request SET Status='Rejected', Handled_by=?, Handled_at=NOW(), Note=? WHERE ID=?")->execute([$by, $note !== '' ? $note : null, $id]); flash('Request rejected.');
    }
    redirect('online_requests.php' . (($_POST['f'] ?? '') === 'all' ? '?f=all' : ''));
}
$f = ($_GET['f'] ?? '') === 'all' ? 'all' : 'pending';
$rows = $pdo->query('SELECT * FROM online_request' . ($f === 'pending' ? " WHERE Status = 'Pending'" : '') . ' ORDER BY Requested_at DESC LIMIT 200')->fetchAll();
$pageTitle = 'Online requests';
include __DIR__ . '/includes/header.php'; ?>
<h1>Online requests</h1>
<p class="muted">Hold requests sent from the public catalog. Approving places a normal hold, which the borrower claims at the library.</p>
<p class="tabs no-print"><a class="<?= $f === 'pending' ? 'on' : '' ?>" href="online_requests.php">Waiting</a><a class="<?= $f === 'all' ? 'on' : '' ?>" href="online_requests.php?f=all">All requests</a></p>
<div class="table-wrap"><table class="data"><thead><tr><th>Asked</th><th>Borrower</th><th>Item</th><th>Status</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e(fdatetime($r['Requested_at'])) ?></td><td><?= e($r['Name']) ?> <small class="muted">(<?= e($r['ID_Number']) ?>)</small></td><td><?= e(shorten($r['Title'], 70)) ?> <small class="muted">(<?= e($r['Accession']) ?>)</small></td>
 <td><?= e($r['Status']) ?><?= $r['Handled_by'] ? ' <small class="muted">· ' . e($r['Handled_by']) . '</small>' : '' ?><?= $r['Note'] ? ' <small class="muted">· ' . e($r['Note']) . '</small>' : '' ?></td>
 <td class="row-actions no-print"><?php if ($r['Status'] === 'Pending'): ?>
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>"><button class="link">Approve</button></form>
  <form method="post" style="display:inline-flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="do" value="reject"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>"><input name="note" maxlength="150" placeholder="Reason (optional)" style="width:150px" aria-label="Reason"><button class="link danger">Reject</button></form>
 <?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted"><?= $f === 'pending' ? 'No requests are waiting.' : 'No requests yet.' ?></td></tr><?php endif; ?></tbody></table></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
