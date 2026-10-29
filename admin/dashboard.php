<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/layout.php';
$u = require_role('admin');
$oc = $u['office_code'];                       // every query below is locked to this office
$office = get_office($oc);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'status') {      // quick status change from the queue
    $q1 = $pdo->prepare('SELECT id, code, status FROM tickets WHERE id=? AND office_code=?'); $q1->execute([(int)($_POST['id'] ?? 0), $oc]); $tk = $q1->fetch();
    if (!$tk) flash('Ticket not found.', 'err');
    elseif (change_ticket_status($pdo, $tk, (string)($_POST['status'] ?? ''))) flash("{$tk['code']} is now " . $_POST['status'] . '. The requester was emailed.' . (in_array($_POST['status'], HISTORY_STATUSES, true) ? ' It moved to Ticket history.' : ''));
    redirect('admin/dashboard.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
}
auto_escalate($pdo, $oc);       // overdue tickets move to Escalated on their own

$fs = $_GET['status'] ?? ''; $fp = $_GET['priority'] ?? ''; $fc = $_GET['category'] ?? ''; $q = trim($_GET['q'] ?? ''); $fm = ($_GET['assigned'] ?? '') ;
$where = 't.office_code=? AND t.status NOT IN ' . status_in(HISTORY_STATUSES); $p = [$oc];   // closed / cancelled tickets live in Ticket history
if (in_array($fs, array_diff(STATUSES, HISTORY_STATUSES), true))   { $where .= ' AND t.status=?';   $p[] = $fs; }
if (in_array($fp, PRIORITIES, true)) { $where .= ' AND t.priority=?'; $p[] = $fp; }
$catList = office_categories($oc, false);
if ($fc !== '' && in_array((int)$fc, array_map('intval', array_column($catList, 'id')), true)) { $where .= ' AND t.category_id=?'; $p[] = (int)$fc; } else $fc = '';
if ($fm === 'me') { $where .= ' AND t.assigned_to=?'; $p[] = $u['id']; } elseif ($fm === 'none') { $where .= ' AND t.assigned_to IS NULL'; } elseif ($fm === 'overdue') { $where .= ' AND ' . sla_overdue_sql('t'); } else $fm = '';
if ($q !== '') { $where .= ' AND (t.subject LIKE ? OR t.code LIKE ? OR us.full_name LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }

$per = 15; $page = max(1, (int)($_GET['page'] ?? 1));
$tot = $pdo->prepare("SELECT COUNT(*) FROM tickets t JOIN users us ON us.id=t.user_id WHERE $where"); $tot->execute($p); $total = (int)$tot->fetchColumn();
$st = $pdo->prepare("SELECT t.*, COALESCE(c.name,'Uncategorized') AS category, us.full_name, us.role AS frole, asg.full_name AS assignee, " . sla_overdue_sql('t') . " AS overdue FROM tickets t JOIN users us ON us.id=t.user_id LEFT JOIN users asg ON asg.id=t.assigned_to LEFT JOIN categories c ON c.id=t.category_id WHERE $where
   ORDER BY FIELD(t.status,'Escalated','Reopened','Open','In Progress','Waiting for User','Resolved','Closed','Cancelled'), FIELD(t.priority,'Urgent','Normal','Low'), t.created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($p); $tickets = $st->fetchAll();

$c = $pdo->prepare('SELECT status, COUNT(*) n FROM tickets WHERE office_code=? GROUP BY status'); $c->execute([$oc]);
$counts = array_fill_keys(STATUSES, 0); foreach ($c->fetchAll() as $r) $counts[$r['status']] = (int)$r['n'];
$od = $pdo->prepare('SELECT COUNT(*) FROM tickets t WHERE t.office_code=? AND ' . sla_overdue_sql('t')); $od->execute([$oc]);
$urg = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE office_code=? AND priority='Urgent' AND status IN " . status_in(OPEN_STATUSES) . ""); $urg->execute([$oc]);

$activeTotal = array_sum($counts) - $counts['Closed'] - $counts['Cancelled'];
$lastQ = $pdo->prepare('SELECT MAX(updated_at) FROM tickets WHERE office_code=?'); $lastQ->execute([$oc]); $lastTs = $lastQ->fetchColumn() ?: null;   // newest change in this office
$odN = (int)$od->fetchColumn(); $urgN = (int)$urg->fetchColumn();
page_start('Ticket queue', e($office['office_name']) . ' · ' . $activeTotal . ' active ticket(s)');
echo dash_hero(e($office['office_name']), 'Ticket queue', '<b>' . $activeTotal . '</b> active' . ($odN ? ' · <b>' . $odN . '</b> overdue' : '') . ($counts['Escalated'] ? ' · <b>' . $counts['Escalated'] . '</b> escalated' : '') . ($urgN ? ' · <b>' . $urgN . '</b> urgent' : '') . '. Work from the top: escalated and urgent tickets are listed first.', $lastTs,
  '<a class="btn btn-primary" href="' . e(url('admin/reports.php')) . '"><i class="fa-solid fa-chart-column"></i>Reports</a><a class="btn" href="' . e(url('admin/records.php')) . '"><i class="fa-solid fa-clock-rotate-left"></i>Ticket history (' . ($counts['Closed'] + $counts['Cancelled']) . ')</a>');
?>
<div class="stats">
  <div class="stat" style="--c:var(--blue)"><i class="fa-solid fa-inbox ic"></i><div class="k">Open</div><div class="v"><?= $counts['Open'] + $counts['Reopened'] ?></div><div class="s">waiting for a reply<?= $counts['Reopened'] ? ' · ' . $counts['Reopened'] . ' reopened' : '' ?></div></div>
  <div class="stat" style="--c:var(--gold)"><i class="fa-solid fa-screwdriver-wrench ic"></i><div class="k">In progress</div><div class="v"><?= $counts['In Progress'] ?></div><div class="s">being handled</div></div>
  <div class="stat" style="--c:var(--red)"><i class="fa-solid fa-arrow-up-right-dots ic"></i><div class="k">Escalated</div><div class="v"><?= $counts['Escalated'] ?></div><div class="s">past target time</div></div>
  <div class="stat" style="--c:var(--red)"><i class="fa-solid fa-triangle-exclamation ic"></i><div class="k">Urgent active</div><div class="v"><?= $urgN ?></div><div class="s">highest priority</div></div>
  <div class="stat" style="--c:var(--red)"><i class="fa-solid fa-hourglass-end ic"></i><div class="k">Overdue</div><div class="v"><?= $odN ?></div><div class="s">target: <?= SLA_HOURS['Urgent'] ?>h urgent · <?= SLA_HOURS['Normal'] ?>h normal · <?= SLA_HOURS['Low'] ?>h low</div></div>
  <div class="stat" style="--c:var(--green)"><i class="fa-solid fa-circle-check ic"></i><div class="k">Resolved</div><div class="v"><?= $counts['Resolved'] ?></div><div class="s">awaiting requester confirmation</div></div>
</div>
<div class="card">
  <div class="card-h"><h3>Active tickets</h3><span class="mono"><?= number_format($total) ?> match</span></div>
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search subject, code or requester…">
    <select name="status"><option value="">All statuses</option><?php foreach (array_diff(STATUSES, HISTORY_STATUSES) as $s): ?><option <?= $fs === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <select name="priority"><option value="">All priorities</option><?php foreach (PRIORITIES as $s): ?><option <?= $fp === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <select name="category"><option value="">All categories</option><?php foreach ($catList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (string)$fc === (string)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?><?= $s['is_hidden'] ? ' (hidden)' : '' ?></option><?php endforeach; ?></select>
    <select name="assigned"><option value="">Anyone</option><option value="me" <?= $fm === 'me' ? 'selected' : '' ?>>Assigned to me</option><option value="none" <?= $fm === 'none' ? 'selected' : '' ?>>Unassigned</option><option value="overdue" <?= $fm === 'overdue' ? 'selected' : '' ?>>Overdue</option></select>
    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i>Filter</button>
    <?php if ($fs || $fp || $fc || $q || $fm): ?><a class="btn" href="dashboard.php">Clear</a><?php endif; ?>
  </form>
  <?php if (!$tickets): ?><div class="empty"><i class="fa-solid fa-inbox"></i>No tickets match.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="stack">
    <thead><tr><th>Ticket</th><th>Requester</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Last update</th></tr></thead><tbody>
    <?php foreach ($tickets as $t): ?><tr>
      <td data-label="Ticket"><a href="<?= url('ticket.php?id=' . $t['id']) ?>"><b><?= e($t['subject']) ?></b></a><div class="sub"><?= e($t['code']) ?></div><a class="btn btn-sm" href="<?= url('ticket.php?id=' . $t['id'] . '&reply=1') ?>" style="margin-top:6px"><i class="fa-solid fa-reply"></i>Reply</a></td>
      <td data-label="Requester"><?= e($t['full_name']) ?> <span class="badge role-<?= e($t['frole']) ?>"><?= e($t['frole']) ?></span></td>
      <td class="mono" data-label="Category"><?= e($t['category']) ?></td><td data-label="Priority"><?= prio_badge($t['priority']) ?></td><td data-label="Status"><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <select name="status" class="st-quick" title="Change status" onchange="this.form.submit()" style="height:32px;font-size:13px"><?php foreach (STATUSES as $s): ?><option <?= $t['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></form>
        <?= $t['overdue'] ? '<span class="badge pr-urgent">overdue</span>' : '' ?></td>
      <td data-label="Assigned"><?= $t['assignee'] ? e($t['assignee']) : '<span class="mono">—</span>' ?></td>
      <td class="mono lu" data-label="Last update"><?= e(fmt_dt($t['updated_at'])) ?><div class="sub"><?= e(time_ago($t['updated_at'])) ?> · created <?= e(date('M j', strtotime($t['created_at']))) ?></div></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php pager($total, $per, $page, array_filter(['q' => $q, 'status' => $fs, 'priority' => $fp, 'category' => $fc, 'assigned' => $fm])); ?>
  <?php endif; ?>
</div>
<?php page_end();
