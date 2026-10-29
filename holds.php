<?php
// Holds: keep an available item on the shelf for one borrower. Uses the Reservation table (Kind = 'Hold').
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db();
$pageTitle = 'Holds';
if (!ce_have_balance() || !ce_has_hold_kind()) ce_need_sql('Holds');
$formError = '';
$keep = ['idnum' => trim($_GET['idnum'] ?? ''), 'accession' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'add') {
        $keep = ['idnum' => trim($_POST['idnum'] ?? ''), 'accession' => trim($_POST['accession'] ?? '')];
        $client = client_by_id($keep['idnum']);
        if (!$client) $formError = 'Borrower not found.';
        else {
            [$ok, $msg] = ce_place_hold($client, $keep['accession']);
            if ($ok) { flash($msg); redirect('holds.php'); }
            $formError = $msg;
        }
    } else {
        $st = $pdo->prepare("SELECT * FROM Reservation WHERE MFN = ? AND Kind = 'Hold'"); $st->execute([(int)($_POST['id'] ?? 0)]); $h = $st->fetch();
        if (!$h) flash('Not done: hold not found.');
        elseif ($do === 'lend') {   // the borrower came for it: lend it now (the borrow rules still apply)
            $client = client_by_id((string)$h['IDnumber']);
            [$ok, $msg] = $client ? circ_checkout($client, (string)$h['Accession']) : [false, 'Borrower not found.'];
            flash(($ok ? '' : 'Not done: ') . $msg);
        } elseif ($do === 'release') {
            $pdo->prepare('DELETE FROM Reservation WHERE MFN = ?')->execute([(int)$h['MFN']]); flash('Hold released. The item is available again.');
        }
        redirect('holds.php?' . http_build_query(['f' => $_POST['f'] ?? 'active']));
    }
}

$f = ($_GET['f'] ?? 'active') === 'all' ? 'all' : 'active';
$today = date('Y-m-d');
$where = "Kind = 'Hold'"; $args = [];
if ($f === 'active') { $where .= " AND (Claimed IS NULL OR Claimed <> 'Yes') AND DateExpired >= :d"; $args['d'] = $today; }
$st = $pdo->prepare("SELECT * FROM Reservation WHERE $where ORDER BY DateReserved DESC, MFN DESC LIMIT 200"); $st->execute($args); $rows = $st->fetchAll();
$q = $pdo->prepare("SELECT COUNT(*) FROM Reservation WHERE Kind = 'Hold' AND (Claimed IS NULL OR Claimed <> 'Yes') AND DateExpired >= ?"); $q->execute([$today]); $nActive = (int)$q->fetchColumn();
include __DIR__ . '/includes/header.php'; ?>
<h1>Holds</h1>
<?php if ($formError): ?><div class="err" role="alert"><?= e($formError) ?></div><?php endif; ?>
<form method="post" class="card form no-print" autocomplete="off">
  <h2 style="margin:0">Hold an item for a borrower</h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="add">
  <label><span>Borrower ID (scan or type)</span><input type="text" name="idnum" value="<?= e($keep['idnum']) ?>" maxlength="30" required autofocus></label>
  <label><span>Accession number</span><input type="text" name="accession" value="<?= e($keep['accession']) ?>" maxlength="50" required></label>
  <p class="muted" style="margin:0">The item must be on the shelf. It is kept for <?= ce_hold_days() ?> open day<?= ce_hold_days() === 1 ? '' : 's' ?> and nobody else can borrow it meanwhile. For an item that is out, use <a href="reservations.php">Reservations</a>.</p>
  <div class="actions"><button class="btn primary">Place hold</button></div>
</form>
<p class="tabs no-print"><a class="<?= $f === 'active' ? 'on' : '' ?>" href="holds.php">Active (<?= $nActive ?>)</a><a class="<?= $f === 'all' ? 'on' : '' ?>" href="holds.php?f=all">All</a></p>
<div class="table-wrap"><table class="data"><thead><tr><th>Borrower</th><th>Accession</th><th>Title</th><th>Held on</th><th>Held until</th><th>Status</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r):
    $claimed = ($r['Claimed'] ?? '') === 'Yes'; $expired = !$claimed && $r['DateExpired'] < $today; ?>
  <tr><td><a href="circulation.php?idnum=<?= e(urlencode($r['IDnumber'])) ?>"><?= e($r['Name']) ?></a> <small class="muted">(<?= e($r['IDnumber']) ?>)</small></td>
    <td><?= e($r['Accession']) ?></td><td><?= e($r['Title']) ?></td><td><?= e(fdate($r['DateReserved'])) ?></td><td class="<?= $expired ? 'late' : '' ?>"><?= e(fdate($r['DateExpired'])) ?></td>
    <td><?= $claimed ? 'Borrowed' : ($expired ? 'Expired' : 'Waiting') ?></td>
    <td class="no-print row-actions"><?php if (!$claimed && !$expired): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="f" value="<?= e($f) ?>"><input type="hidden" name="id" value="<?= (int)$r['MFN'] ?>"><button class="link" name="do" value="lend">Lend now</button></form><?php endif; ?>
      <?php if (!$claimed): ?><form method="post" data-confirm="Release this hold?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="f" value="<?= e($f) ?>"><input type="hidden" name="id" value="<?= (int)$r['MFN'] ?>"><button class="link danger" name="do" value="release">Release</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="7" class="muted">No holds.</td></tr><?php endif; ?></tbody></table></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
