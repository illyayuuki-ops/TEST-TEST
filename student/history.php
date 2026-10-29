<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/layout.php';
$u = require_role('student');

auto_close_waiting($pdo, ['user_id' => $u['id']]);   // tickets that waited too long close by themselves and land here
$fs = $_GET['status'] ?? ''; $fo = $_GET['office'] ?? ''; $q = trim($_GET['q'] ?? '');
$where = 't.user_id=? AND t.status IN ' . status_in(HISTORY_STATUSES); $p = [$u['id']];
if (in_array($fs, HISTORY_STATUSES, true)) { $where .= ' AND t.status=?'; $p[] = $fs; } else $fs = '';
if ($fo !== '') { $where .= ' AND t.office_code=?'; $p[] = $fo; }
if ($q !== '') { $where .= ' AND (t.subject LIKE ? OR t.code LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }

$per = 12; $page = max(1, (int)($_GET['page'] ?? 1));
$tot = $pdo->prepare("SELECT COUNT(*) FROM tickets t WHERE $where"); $tot->execute($p); $total = (int)$tot->fetchColumn();
$st = $pdo->prepare("SELECT t.*, COALESCE(c.name,'Uncategorized') AS category, o.office_name, o.brand_color, o.brand_icon FROM tickets t JOIN offices o ON o.office_code=t.office_code LEFT JOIN categories c ON c.id=t.category_id
                     WHERE $where ORDER BY COALESCE(t.resolved_at, t.updated_at) DESC, t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($p); $tickets = $st->fetchAll();
$myOffices = $pdo->prepare('SELECT DISTINCT o.office_code, o.office_name FROM tickets t JOIN offices o ON o.office_code=t.office_code WHERE t.user_id=? AND t.status IN ' . status_in(HISTORY_STATUSES));
$myOffices->execute([$u['id']]); $myOffices = $myOffices->fetchAll();

page_start('Ticket history', 'Finished tickets · ' . number_format($total) . ' record(s)',
  '<a class="btn" href="' . url('student/dashboard.php') . '"><i class="fa-solid fa-ticket"></i>My tickets</a>');
?>
<div class="card">
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search subject or ticket code…">
    <select name="status"><option value="">Closed &amp; cancelled</option><?php foreach (HISTORY_STATUSES as $s): ?><option <?= $fs === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <select name="office"><option value="">All offices</option><?php foreach ($myOffices as $o): ?><option value="<?= e($o['office_code']) ?>" <?= $fo === $o['office_code'] ? 'selected' : '' ?>><?= e($o['office_name']) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i>Filter</button>
    <?php if ($fs || $fo || $q): ?><a class="btn" href="history.php">Clear</a><?php endif; ?>
  </form>
  <?php if (!$tickets): ?>
    <div class="empty"><i class="fa-solid fa-clock-rotate-left"></i>No finished tickets yet. Tickets you close or cancel will appear here.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="stack">
    <thead><tr><th>Ticket</th><th>Office</th><th>Category</th><th>Result</th><th>Finished</th><th></th></tr></thead><tbody>
    <?php foreach ($tickets as $t): ?><tr>
      <td data-label="Ticket"><a href="<?= url('ticket.php?id=' . $t['id']) ?>"><b><?= e($t['subject']) ?></b></a><div class="sub"><?= e($t['code']) ?></div></td>
      <td data-label="Office"><?= office_chip($t) ?></td>
      <td class="mono" data-label="Category"><?= e($t['category']) ?></td>
      <td data-label="Result"><?= badge($t['status']) ?></td>
      <td class="mono" data-label="Finished"><?= date('M j, Y g:i A', strtotime($t['resolved_at'] ?: $t['updated_at'])) ?></td>
      <td><?php if (can_reopen($t)): ?><a class="btn btn-sm" href="<?= url('ticket.php?id=' . $t['id']) ?>"><i class="fa-solid fa-rotate-left"></i>Reopen</a>
          <?php else: ?><a class="btn btn-sm" href="<?= url('ticket.php?id=' . $t['id']) ?>">View<i class="fa-solid fa-arrow-right"></i></a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php pager($total, $per, $page, array_filter(['q' => $q, 'status' => $fs, 'office' => $fo])); ?>
  <?php endif; ?>
</div>
<?php page_end();
