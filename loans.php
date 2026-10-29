<?php
// All loans: on loan now, overdue, lost, or returned. Also prints an overdue notice for one borrower.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db();
$pageTitle = 'Borrowed items';

// ---------- settle a lost item ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'settle_lost') {
    csrf_check();
    [$ok, $msg] = circ_settle_lost((int)($_POST['id'] ?? 0), trim((string)($_POST['idnum'] ?? '')), round((float)str_replace(',', '', (string)($_POST['amount'] ?? '0')), 2), $_SESSION['name'] ?? ($_SESSION['user'] ?? ''));
    flash(($ok ? '' : 'Not done: ') . $msg);
    redirect('loans.php?f=lost');
}

// ---------- printable overdue notice ----------
if (isset($_GET['notice'])) {
    $id = trim($_GET['notice']);
    $client = client_by_id($id);
    $st = $pdo->prepare("SELECT * FROM Borrower WHERE ID_Number = ? AND Date_returned IS NULL AND Status <> 'Lost' AND Due_date < CURDATE() ORDER BY Due_date");
    $st->execute([$id]); $rows = $st->fetchAll();
    $read = function ($f) { $p = __DIR__ . '/data/lookups/' . $f; return is_file($p) ? trim(file_get_contents($p)) : ''; };
    $body = $read('Notice.txt'); $sign = trim(preg_split('/[;\r\n]+/', $read('Signatory.txt'))[0] ?? '');
    include __DIR__ . '/includes/header.php'; ?>
    <p class="no-print"><a href="loans.php?f=overdue">‹ Overdue items</a> · <button class="btn" data-print>Print</button></p>
    <div class="card" style="max-width:720px">
      <h2><?= e($CONFIG['library_name']) ?> · Overdue notice</h2>
      <p><?= e(date('F j, Y')) ?></p>
      <p>To: <strong><?= e($client['Name'] ?? $id) ?></strong> (ID <?= e($id) ?>)</p>
      <p><?= e($body) ?></p>
      <table class="data"><thead><tr><th>Accession</th><th>Title</th><th>Due</th><th>Days late</th><th>Fine</th></tr></thead><tbody>
      <?php $tot = 0; foreach ($rows as $r): $late = late_days($r['Due_date']); $fine = $late * fine_rate($client['User_class'] ?? null, is_overnight($r['Library'])); $tot += $fine; ?>
        <tr><td><?= e($r['Accession_number']) ?></td><td><?= e($r['Title']) ?></td><td><?= e(fdate($r['Due_date'])) ?></td><td><?= $late ?></td><td><?= e(money($fine)) ?></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted">No overdue items.</td></tr><?php endif; ?></tbody></table>
      <p><strong>Total fine so far: <?= e(money($tot)) ?></strong></p>
      <?php if ($sign): ?><p style="margin-top:32px"><?= e($sign) ?></p><?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; exit;
}

// ---------- list ----------
$f = $_GET['f'] ?? 'current';
$q = trim($_GET['q'] ?? '');
$per = $CONFIG['per_page']; $page = max(1, (int)($_GET['p'] ?? 1));
$where = ['1=1']; $args = [];
switch ($f) {
    case 'overdue':  $where[] = "b.Date_returned IS NULL AND b.Status <> 'Lost' AND b.Due_date < CURDATE()"; break;
    case 'lost':     $where[] = "b.Date_returned IS NULL AND b.Status = 'Lost'"; break;
    case 'returned': $where[] = 'b.Date_returned IS NOT NULL'; break;
    default: $f = 'current'; $where[] = 'b.Date_returned IS NULL';
}
if ($q !== '') {
    $where[] = '(b.Accession_number LIKE :q1 OR b.Title LIKE :q2 OR b.ID_Number LIKE :q3 OR c.Name LIKE :q4)';
    foreach ([1, 2, 3, 4] as $i) $args["q$i"] = "%$q%";
}
$w = implode(' AND ', $where);
$from = 'FROM Borrower b LEFT JOIN Client c ON c.IDnum = b.ID_Number';
$cnt = $pdo->prepare("SELECT COUNT(*) $from WHERE $w"); $cnt->execute($args); $total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
$order = $f === 'returned' ? 'b.Date_returned DESC' : 'b.Due_date ASC';
$st = $pdo->prepare("SELECT b.*, c.Name, c.User_class $from WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args); $rows = $st->fetchAll();
$qs = fn($p) => 'loans.php?' . http_build_query(['f' => $f, 'q' => $q, 'p' => $p]);
$tabs = ['current' => 'On loan', 'overdue' => 'Overdue', 'lost' => 'Lost', 'returned' => 'Returned'];
include __DIR__ . '/includes/header.php'; ?>
<h1>Borrowed items</h1>
<p class="tabs no-print"><?php foreach ($tabs as $k => $label): ?><a class="<?= $k === $f ? 'on' : '' ?>" href="loans.php?f=<?= e($k) ?>"><?= e($label) ?></a><?php endforeach; ?></p>
<form class="toolbar no-print" method="get"><input type="hidden" name="f" value="<?= e($f) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search accession, title, borrower name or ID…"><button class="btn">Search</button>
  <?php if ($q !== ''): ?><a class="btn ghost" href="loans.php?f=<?= e($f) ?>">Clear</a><?php endif; ?>
  <button type="button" class="btn ghost" data-print>Print</button></form>
<p class="muted"><?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?> · page <?= $page ?> of <?= $pages ?></p>
<div class="table-wrap"><table class="data" data-sortable><thead><tr>
  <th>Borrower</th><th>Accession</th><th>Title</th><th>Borrowed</th><th><?= $f === 'returned' ? 'Returned' : 'Due' ?></th><th>Days late</th><th>Fine</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r):
    $isOpen = $r['Date_returned'] === null; $late = ($isOpen && $r['Status'] !== 'Lost') ? late_days($r['Due_date']) : (int)$r['Overdue'];
    $fine = $late * fine_rate($r['User_class'], is_overnight($r['Library'])); ?>
  <tr><td><a href="circulation.php?idnum=<?= e(urlencode($r['ID_Number'])) ?>"><?= e($r['Name'] ?: $r['ID_Number']) ?></a></td>
    <td><?= e($r['Accession_number']) ?></td><td><?= e($r['Title']) ?></td><td><?= e(fdate($r['Date_Borrowed'])) ?></td>
    <td class="<?= ($isOpen && $late) ? 'late' : '' ?>"><?= e(fdate($isOpen ? $r['Due_date'] : $r['Date_returned'])) ?></td>
    <td><?= $late ?: '' ?></td><td><?= $fine > 0 ? e(money($fine)) : '' ?></td>
    <td class="no-print row-actions"><?php if ($isOpen && $late && $r['Status'] !== 'Lost'): ?><a href="loans.php?notice=<?= e(urlencode($r['ID_Number'])) ?>">Notice</a><?php endif; ?>
      <?php if ($isOpen && $r['Status'] === 'Lost'): ?><form method="post" style="display:inline-flex;gap:6px" data-confirm="Settle this lost item? The loan will be closed as Lost/Paid."><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="settle_lost"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><input type="hidden" name="idnum" value="<?= e($r['ID_Number']) ?>"><input type="number" name="amount" min="0" step="any" value="0" style="width:90px" aria-label="Amount paid"><button class="link">Settle</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8" class="muted">Nothing here.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($pages > 1): ?><nav class="pager no-print">
  <?php if ($page > 1): ?><a href="<?= e($qs($page - 1)) ?>">‹ Prev</a><?php endif; ?><span>Page <?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= e($qs($page + 1)) ?>">Next ›</a><?php endif; ?></nav><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
